<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\Sale;
use App\Models\User;
use App\Models\Category;
use App\Models\CategoryCommission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
//Use Para Excel
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\CommissionReportExport;
use App\Exports\CommissionSellerDetailExport;



class CommissionReportController extends Controller
{
    // fallback total turns
    protected int $TOTAL_TURNS = 315;

    // fragancias handling
    const FRAG_KEY = 'fragancias';
    const FRAG_CODES = [10, 11, 12];
    protected int $MIN_PCT_TO_QUALIFY = 80;

    public function myCommissions(Request $request)
{
    $userId = auth()->id();

    if (!$userId) {
        return response()->json(['message' => 'No autenticado'], 401);
    }

    // reutilizamos TODA la lógica existente
    return $this->bySellerDetail($request, $userId);
}


    private function categoryOrder(string $code): int
{
    return match ($code) {
        '13' => 1,
        '14' => 2,
        '15' => 3,
        '16' => 4,
        '18' => 5,
        self::FRAG_KEY => 6,
        '19' => 7,
        '17' => 8,
        '22' => 9,
        '21' => 10,
        default => 999,
    };
}


    private function categoryName(string $code): string
{
    return match ($code) {
        '19' => 'Gifts',
        '14' => 'Joyeria',
        '15' => 'Gafas',
        '16' => 'Chocolates',
        '18' => 'Licores',        
        self::FRAG_KEY => 'Fragancias',
        '13' => 'Skin care',
        '17' => 'Tabaco',
        '22' => 'Relojes',
        '21' => 'Electrónicos',
        default => strtoupper($code),
    };
}


    protected function resolveBudget(Request $request, ?int $routeBudgetId = null): Budget
    {
        $budgetId = $routeBudgetId ?? $request->query('budget_id');

        if (!$budgetId) {
            abort(422, "budget_id es obligatorio para esta operación.");
        }

        $budget = Budget::find($budgetId);

        abort_if(!$budget, 404, "Presupuesto {$budgetId} no encontrado");

        return $budget;
    }

    private function normalizeClassification($raw)
    {
        $raw = (string) ($raw ?? '');
        $raw = trim($raw);
        if ($raw === '') return 'sin_categoria';

        if (is_numeric($raw) && in_array((int)$raw, self::FRAG_CODES, true)) {
            return self::FRAG_KEY;
        }

        $low = mb_strtolower($raw);

        if (str_contains($low, 'frag') || str_contains($low, 'perf')) {
            return self::FRAG_KEY;
        }

        return trim($low);
    }

    private function getSqlClassificationCase(): string
    {
        $codes = implode('|', array_map('intval', self::FRAG_CODES));

        return "CASE
            WHEN CAST(products.classification AS CHAR) REGEXP '^(?:{$codes})$' THEN '" . self::FRAG_KEY . "'
            WHEN LOWER(CAST(products.classification AS CHAR)) LIKE '%frag%' THEN '" . self::FRAG_KEY . "'
            WHEN LOWER(CAST(products.classification AS CHAR)) LIKE '%perf%' THEN '" . self::FRAG_KEY . "'
            ELSE TRIM(COALESCE(products.classification, 'sin_categoria'))
        END";
    }

    /**
     * Fast report using aggregated tables:
     *  - budget_user_category_totals (per budget,user,category_group)
     *  - budget_user_totals (per budget,user)
     *
     * Avoids per-sale `commissions` scans and is optimized for speed.
     */
    public function bySeller(Request $request)
    {
        
        $budget = $this->resolveBudget($request);
        $totalTurns = $budget->total_turns ?? $this->TOTAL_TURNS;
        $roleName = $request->query('role_name');

        // categories model: participation mapping
        $categoriesModel = Category::select('id', 'classification_code', 'participation_pct')->get();

        $participationByCode = [];
        foreach ($categoriesModel as $c) {
            $key = $this->normalizeClassification($c->classification_code);
            if (!isset($participationByCode[$key])) $participationByCode[$key] = 0.0;
            $participationByCode[$key] += (float)($c->participation_pct ?? 0.0);
        }

        // --- aggregated category totals for the budget (global across users) ---
        $categoryTotals = DB::table('budget_user_category_totals')
            ->selectRaw("category_group AS classification, SUM(sales_usd) AS sales_usd, SUM(sales_cop) AS sales_cop, SUM(commission_cop) AS commission_cop")
            ->where('budget_id', $budget->id)
            ->groupBy('category_group')
            ->get();

        $categoriesSummary = [];
        foreach ($categoryTotals as $r) {
            $classification = $this->normalizeClassification($r->classification);
    $categoryName = $this->categoryName($classification);
    if ($categoryName === '') {
        continue;
    }
            $participation = isset($participationByCode[$classification]) ? (float)$participationByCode[$classification] : 0.0;
            $categoryBudgetUsd = round($budget->target_amount * ($participation / 100), 2);
            $salesUsd = (float)$r->sales_usd;
            $salesCop = (float)$r->sales_cop;

            $pctOfCategory = $categoryBudgetUsd > 0 ? round(($salesUsd / $categoryBudgetUsd) * 100, 2) : null;
            $qualifies = ($pctOfCategory !== null) && ($pctOfCategory >= $this->MIN_PCT_TO_QUALIFY);

            $commissionCop = (float)$r->commission_cop;
            $commissionUsd = null;
            if ($salesUsd > 0 && $salesCop > 0) {
                $trm = $salesCop / $salesUsd;
                if ($trm > 0) $commissionUsd = round($commissionCop / $trm, 2);
            }

            $categoriesSummary[$classification] = [
                'classification' => $classification,
                'participation_pct' => $participation,
                'category_budget_usd' => $categoryBudgetUsd,
                'sales_usd' => round($salesUsd, 2),
                'sales_cop' => round($salesCop, 2),
                'pct_of_category' => $pctOfCategory,
                'qualifies' => $qualifies,
                'commission_cop' => round($commissionCop, 2),
                'commission_usd' => $commissionUsd,
            ];
        }

        //
        // TICKETS (we still compute from sales; acceptable for reporting)
        //
        $folioRaw = "COALESCE(sales.folio, CONCAT('folio_null_', DATE(sales.sale_date), '_', COALESCE(sales.pdv, '')))";

        $ticketRows = Sale::selectRaw("sales.seller_id, {$folioRaw} AS folio_key, SUM(COALESCE(sales.amount_cop,0)) AS ticket_cop, SUM(COALESCE(sales.value_usd,0)) AS ticket_usd, SUM(COALESCE(sales.quantity,1)) AS units_count")
            ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date])
            ->when(Schema::hasColumn('sales','budget_id'), function ($q) use ($budget) {
                return $q->where('sales.budget_id', $budget->id);
            })
            ->groupBy('sales.seller_id', DB::raw($folioRaw))
            ->get();

        $globalTicketRows = Sale::selectRaw("{$folioRaw} AS folio_key, SUM(COALESCE(sales.amount_cop,0)) AS ticket_cop, SUM(COALESCE(sales.value_usd,0)) AS ticket_usd, SUM(COALESCE(sales.quantity,1)) AS units_count")
            ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date])
            ->when(Schema::hasColumn('sales','budget_id'), function ($q) use ($budget) {
                return $q->where('sales.budget_id', $budget->id);
            })
            ->groupBy(DB::raw($folioRaw))
            ->get();

        // aggregate tickets by seller
        $ticketsBySeller = [];
        foreach ($ticketRows as $t) {
            $sid = (int)$t->seller_id;
            if (!isset($ticketsBySeller[$sid])) {
                $ticketsBySeller[$sid] = [
                    'tickets_count' => 0,
                    'units_total' => 0,
                    'sum_ticket_usd' => 0.0,
                    'sum_ticket_cop' => 0.0,
                    'max_ticket_usd' => null,
                    'max_ticket_cop' => null,
                    'min_ticket_usd' => null,
                    'min_ticket_cop' => null,
                ];
            }
            $entry = &$ticketsBySeller[$sid];
            $entry['tickets_count'] += 1;
            $entry['sum_ticket_usd'] += (float)$t->ticket_usd;
            $entry['sum_ticket_cop'] += (float)$t->ticket_cop;
            $entry['units_total'] += (int)$t->units_count;

            $usd = (float)$t->ticket_usd;
            $cop = (float)$t->ticket_cop;

            if (is_null($entry['max_ticket_usd']) || $usd > $entry['max_ticket_usd']) $entry['max_ticket_usd'] = $usd;
            if (is_null($entry['min_ticket_usd']) || $usd < $entry['min_ticket_usd']) $entry['min_ticket_usd'] = $usd;

            if (is_null($entry['max_ticket_cop']) || $cop > $entry['max_ticket_cop']) $entry['max_ticket_cop'] = $cop;
            if (is_null($entry['min_ticket_cop']) || $cop < $entry['min_ticket_cop']) $entry['min_ticket_cop'] = $cop;
            unset($entry);
        }

        foreach ($ticketsBySeller as $sid => $t) {
            $tickets = $t['tickets_count'] ?: 1;
            $ticketsBySeller[$sid]['avg_ticket_usd'] = round($t['sum_ticket_usd'] / $tickets, 2);
            $ticketsBySeller[$sid]['avg_ticket_cop'] = round($t['sum_ticket_cop'] / $tickets, 2);
            $ticketsBySeller[$sid]['avg_units_per_ticket'] = $t['tickets_count'] > 0 ? round($t['units_total'] / $t['tickets_count'], 2) : null;
            unset($ticketsBySeller[$sid]['sum_ticket_usd'], $ticketsBySeller[$sid]['sum_ticket_cop'], $ticketsBySeller[$sid]['units_total']);
        }

        // global tickets summary
        $globalTicketsSummary = [
            'tickets_count' => 0,
            'avg_ticket_usd' => null,
            'avg_ticket_cop' => null,
            'max_ticket_usd' => null,
            'max_ticket_cop' => null,
            'min_ticket_usd' => null,
            'min_ticket_cop' => null,
            'avg_units_per_ticket' => null,
            'best_seller_by_avg_ticket' => null,
        ];

        $totalTicketsGlobal = $globalTicketRows->count();
        $totalUnitsGlobal = $globalTicketRows->sum(fn($r) => (int)$r->units_count);
        $totalUsdGlobal = $globalTicketRows->sum(fn($r) => (float)$r->ticket_usd);
        $totalCopGlobal = $globalTicketRows->sum(fn($r) => (float)$r->ticket_cop);

        if ($totalTicketsGlobal > 0) {
            $globalTicketsSummary['tickets_count'] = $totalTicketsGlobal;
            $globalTicketsSummary['avg_ticket_usd'] = round($totalUsdGlobal / $totalTicketsGlobal, 2);
            $globalTicketsSummary['avg_ticket_cop'] = round($totalCopGlobal / $totalTicketsGlobal, 2);
            $globalTicketsSummary['max_ticket_usd'] = $globalTicketRows->max('ticket_usd');
            $globalTicketsSummary['max_ticket_cop'] = $globalTicketRows->max('ticket_cop');
            $globalTicketsSummary['min_ticket_usd'] = $globalTicketRows->min('ticket_usd');
            $globalTicketsSummary['min_ticket_cop'] = $globalTicketRows->min('ticket_cop');
            $globalTicketsSummary['avg_units_per_ticket'] = $totalTicketsGlobal > 0 ? round($totalUnitsGlobal / $totalTicketsGlobal, 2) : null;

            $bestSid = null;
            $bestAvg = null;
            foreach ($ticketsBySeller as $sid => $m) {
                if (isset($m['avg_ticket_usd'])) {
                    if (is_null($bestAvg) || $m['avg_ticket_usd'] > $bestAvg) {
                        $bestAvg = $m['avg_ticket_usd'];
                        $bestSid = $sid;
                    }
                }
            }

            if ($bestSid !== null) {
                $bestUser = User::select('id','name')->find($bestSid);
                $globalTicketsSummary['best_seller_by_avg_ticket'] = $bestUser ? ['user_id' => $bestUser->id, 'seller' => $bestUser->name, 'avg_ticket_usd' => $bestAvg] : null;
            }
        }

        // --- SELLERS: use budget_user_totals + users table (fast) ---
        $query = User::query()
            ->selectRaw("users.id AS user_id, users.name AS seller, COALESCE(but.assigned_turns,0) AS assignedTurns, COALESCE(butot.total_sales_cop,0) AS total_sales_cop, COALESCE(butot.total_sales_usd,0) AS total_sales_usd, COALESCE(butot.total_commission_cop,0) AS total_commission_cop")
            ->leftJoin('budget_user_turns as but', function ($join) use ($budget) {
                $join->on('but.user_id', '=', 'users.id')->where('but.budget_id', '=', $budget->id);
            })
            ->leftJoin('budget_user_totals as butot', function ($join) use ($budget) {
                $join->on('butot.user_id', '=', 'users.id')->where('butot.budget_id', '=', $budget->id);
            })
            ->orderByDesc('butot.total_sales_cop');

        if ($roleName) {
            $query->join('user_roles', function ($join) use ($budget) {
                    $join->on('user_roles.user_id', '=', 'users.id')
                        ->whereRaw("user_roles.start_date <= ?", [$budget->end_date])
                        ->whereRaw("COALESCE(user_roles.end_date, ?) >= ?", [$budget->end_date, $budget->start_date]);
                })
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('roles.name', $roleName);
        }

        $rows = $query->get();

        // Attach ticket metrics
        $rows = $rows->map(function ($r) use ($ticketsBySeller) {
            $sid = (int)$r->user_id;
            $ticketMetrics = $ticketsBySeller[$sid] ?? [
                'tickets_count' => 0,
                'avg_ticket_usd' => null,
                'avg_ticket_cop' => null,
                'avg_units_per_ticket' => null,
                'max_ticket_usd' => null,
                'min_ticket_usd' => null,
            ];
            $r->tickets = $ticketMetrics;
            return $r;
        });

        // avg_trm per user using trms table (calculate only for users returned)
        if ($rows->isNotEmpty()) {
            $userIds = $rows->pluck('user_id')->unique()->values()->all();

            $saleDatesPerUser = Sale::query()
                ->whereIn('seller_id', $userIds)
                ->whereBetween('sale_date', [$budget->start_date, $budget->end_date])
                ->when(Schema::hasColumn('sales','budget_id'), function ($q) use ($budget) {
                    return $q->where('sales.budget_id', $budget->id);
                })
                ->select('seller_id', 'sale_date')
                ->distinct()
                ->get()
                ->groupBy('seller_id')
                ->map(function ($g) {
                    return $g->pluck('sale_date')->unique()->values()->all();
                });

            $allDates = [];
            foreach ($saleDatesPerUser as $dates) {
                foreach ($dates as $d) {
                    $allDates[$d] = true;
                }
            }
            $allDates = array_keys($allDates);

            $trmByDate = [];
            if (!empty($allDates)) {
                $trmRows = DB::table('trms')
                    ->select('date', DB::raw('AVG(value) as avg_value'))
                    ->whereIn('date', $allDates)
                    ->groupBy('date')
                    ->get();

                foreach ($trmRows as $t) {
                    $trmByDate[$t->date] = (float)$t->avg_value;
                }
            }

            $rows = $rows->map(function ($r) use ($saleDatesPerUser, $trmByDate) {
                $userId = $r->user_id;
                $dates = $saleDatesPerUser[$userId] ?? [];
                $vals = [];
                foreach ($dates as $d) {
                    if (isset($trmByDate[$d])) $vals[] = $trmByDate[$d];
                }
                if (!empty($vals)) {
                    $avg = array_sum($vals) / count($vals);
                    $r->avg_trm = round($avg, 2);
                } else {
                    $r->avg_trm = null;
                }
                return $r;
            });

            $rows = $rows->map(function ($r) {
                $commissionUsd = null;

                if (
                    isset($r->total_commission_cop) &&
                    $r->total_commission_cop > 0 &&
                    isset($r->avg_trm) &&
                    $r->avg_trm > 0
                ) {
                    $commissionUsd = round($r->total_commission_cop / $r->avg_trm, 2);
                }

                $r->total_commission_usd = $commissionUsd;
                return $r;
            });

        }

        // global progress based on Sale totals (USD)
        $totalUsdQuery = Sale::query()
            ->whereBetween('sale_date', [$budget->start_date, $budget->end_date]);
        if (Schema::hasColumn('sales', 'budget_id')) $totalUsdQuery->where('sales.budget_id', $budget->id);
        $totalUsd = $totalUsdQuery->sum(DB::raw('COALESCE(value_usd,0)'));

        $pct = $budget->target_amount > 0 ? round(($totalUsd / $budget->target_amount) * 100, 2) : 0;
        $isProvisionalGlobal = $pct < $this->MIN_PCT_TO_QUALIFY;

        $requiredUsd = round($budget->target_amount * ($this->MIN_PCT_TO_QUALIFY / 100), 2);
        $missingUsd = max(0, round($requiredUsd - $totalUsd, 2));

        $totalAssigned = (int) DB::table('budget_user_turns')->where('budget_id', $budget->id)->sum('assigned_turns');
        $remainingTurns = max(0, $totalTurns - $totalAssigned);

        // totals from aggregated table
        $totalCommissionCop = DB::table('budget_user_totals')->where('budget_id', $budget->id)->sum('total_commission_cop');

        $totalCommissionUsd = null;
        if ($totalCommissionCop > 0 && $totalUsd > 0) {
            $trmGlobal = $totalCopGlobal > 0 ? ($totalCopGlobal / $totalUsd) : null;
            if ($trmGlobal && $trmGlobal > 0) $totalCommissionUsd = round($totalCommissionCop / $trmGlobal, 2);
        }

        return response()->json([
            'active' => true,
            'currency' => 'COP',
            'budget' => [
                'id' => $budget->id,
                'name' => $budget->name,
                'start_date' => $budget->start_date,
                'end_date' => $budget->end_date,
                'target_amount' => $budget->target_amount,
                'min_pct_to_qualify' => $this->MIN_PCT_TO_QUALIFY,
                'total_turns' => $budget->total_turns
            ],
            'progress' => [
                'pct' => $pct,
                'min_pct' => $this->MIN_PCT_TO_QUALIFY,
                'missing_usd' => $missingUsd,
                'is_provisional_global' => $isProvisionalGlobal,
                'total_usd' => round($totalUsd, 2),
                'required_usd' => $requiredUsd,
                'total_commission_cop' => round($totalCommissionCop, 2),
                'total_commission_usd' => $totalCommissionUsd,
            ],
            'categories_summary' => array_values($categoriesSummary),
            'tickets_summary' => $globalTicketsSummary,
            'turns' => [
                'total' => $totalTurns,
                'assigned_total' => $totalAssigned,
                'remaining' => $remainingTurns,
            ],
            'sellers' => $rows
        ]);
    }

    /**
     * Detail per seller using aggregated tables where possible and fallback to sales for ticket-level rows.
     */
    public function bySellerDetail(Request $request, $userId)
    {
        $budget = $this->resolveBudget($request);
        $totalTurns = $budget->total_turns ?? $this->TOTAL_TURNS;

        // Sales rows for the user (no per-sale commission stored in this fast-mode)
        $sales = Sale::select(
                'sales.id as sale_id',
                'sales.sale_date',
                'sales.folio',
                'sales.pdv',
                'products.description as product',
                'products.classification as category_code',
                'products.classification_desc as category_desc',
                'sales.amount_cop',
                'sales.value_usd',
                'sales.exchange_rate'
            )
            ->leftJoin('products', 'sales.product_id', '=', 'products.id')
            ->where('sales.seller_id', $userId)
            ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date])
            ->when(Schema::hasColumn('sales','budget_id'), function ($q) use ($budget) {
                return $q->where('sales.budget_id', $budget->id);
            })
            ->orderBy('sales.sale_date')
            ->get();

        $saleDates = $sales->pluck('sale_date')->unique()->values()->all();

        // user tickets (group by folio)
        $userTicketRows = Sale::selectRaw("COALESCE(sales.folio, CONCAT('folio_null_', DATE(sales.sale_date), '_', COALESCE(sales.pdv, ''))) AS folio_key, SUM(COALESCE(sales.amount_cop,0)) AS ticket_cop, SUM(COALESCE(sales.value_usd,0)) AS ticket_usd, COUNT(*) AS lines_count, SUM(COALESCE(sales.quantity,1)) AS units_count, MIN(sales.sale_date) AS sale_date")
            ->where('sales.seller_id', $userId)
            ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date])
            ->when(Schema::hasColumn('sales','budget_id'), function ($q) use ($budget) {
                return $q->where('sales.budget_id', $budget->id);
            })
            ->groupBy(DB::raw("COALESCE(sales.folio, CONCAT('folio_null_', DATE(sales.sale_date), '_', COALESCE(sales.pdv, '')))"))
            ->orderByDesc('ticket_usd')
            ->get();

        $userTicketsList = $userTicketRows->map(function ($t) {
            return [
                'folio' => (string)$t->folio_key,
                'ticket_usd' => round((float)$t->ticket_usd, 2),
                'ticket_cop' => round((float)$t->ticket_cop, 2),
                'lines_count' => (int)$t->lines_count,
                'units_count' => (int)$t->units_count,
                'sale_date' => $t->sale_date,
            ];
        })->values();

        $userTicketsSummary = [
            'tickets_count' => $userTicketsList->count(),
            'avg_ticket_usd' => $userTicketsList->count() ? round($userTicketsList->avg('ticket_usd'), 2) : null,
            'avg_ticket_cop' => $userTicketsList->count() ? round($userTicketsList->avg('ticket_cop'), 2) : null,
            'avg_units_per_ticket' => $userTicketsList->count() ? round($userTicketsList->avg('units_count'), 2) : null,
            'max_ticket_usd' => $userTicketsList->count() ? $userTicketsList->max('ticket_usd') : null,
            'max_ticket_cop' => $userTicketsList->count() ? $userTicketsList->max('ticket_cop') : null,
            'min_ticket_usd' => $userTicketsList->count() ? $userTicketsList->min('ticket_usd') : null,
            'min_ticket_cop' => $userTicketsList->count() ? $userTicketsList->min('ticket_cop') : null,
        ];

        // avg trm for user from trms table
        $avgTrmForUser = null;
        if (!empty($saleDates)) {
            $trmRows = DB::table('trms')->select('date', DB::raw('AVG(value) as avg_value'))->whereIn('date', $saleDates)->groupBy('date')->get();
            $trmValues = [];
            foreach ($trmRows as $t) $trmValues[] = (float)$t->avg_value;
            if (!empty($trmValues)) $avgTrmForUser = round(array_sum($trmValues) / count($trmValues), 2);
        }

        // categories for user from aggregated table
        $userCategoryRows = DB::table('budget_user_category_totals')
            ->where('budget_id', $budget->id)
            ->where('user_id', $userId)
            ->get();

        $categoriesSummary = [];
        $categoriesModel = Category::select('id','classification_code','participation_pct')->get();
        $categoryGroupMap = [];
        foreach ($categoriesModel as $c) {
            $code = (string)$c->classification_code;
            $group = (is_numeric($code) && in_array((int)$code, self::FRAG_CODES, true)) ? self::FRAG_KEY : $this->normalizeClassification($code);
            if (!isset($categoryGroupMap[$group])) {
                $categoryGroupMap[$group] = ['category_id' => $c->id, 'participation_pct' => (float)$c->participation_pct];
            } else {
                $categoryGroupMap[$group]['participation_pct'] += (float)$c->participation_pct;
            }
        }
        // normalizar claves para que ventas y categorías coincidan
$normalizedGroup = function ($v) {
    return $this->normalizeClassification($v);
};
        $assignedToUser = (int) DB::table('budget_user_turns')->where('budget_id', $budget->id)->where('user_id', $userId)->value('assigned_turns');

        $userBudgetUsd = ($budget->total_turns ?? $this->TOTAL_TURNS) > 0
            ? round($budget->target_amount * ($assignedToUser / ($budget->total_turns ?? $this->TOTAL_TURNS)), 2)
            : 0.0;
$userBudgetUsd = ($budget->total_turns ?? $this->TOTAL_TURNS) > 0
    ? round(
        $budget->target_amount * ($assignedToUser / ($budget->total_turns ?? $this->TOTAL_TURNS)),
        2
    )
    : 0.0;


        foreach ($userCategoryRows as $r) {

            $classification = $normalizedGroup($r->category_group);
            $participation = $categoryGroupMap[$classification]['participation_pct'] ?? 0.0;
            $categoryBudgetUsd = round(
    $userBudgetUsd * ($participation / 100),
    2
);


            $salesUsd = (float)$r->sales_usd;
            $salesCop = (float)$r->sales_cop;
            $commissionCop = (float)$r->commission_cop;

            $pctOfCategory = $categoryBudgetUsd > 0 ? round(($salesUsd / $categoryBudgetUsd) * 100, 2) : null;
            $qualifies = $pctOfCategory !== null && $pctOfCategory >= $this->MIN_PCT_TO_QUALIFY;

            $commissionUsd = null;
            if ($salesUsd > 0 && $salesCop > 0) {
                $trm = $salesCop / $salesUsd;
                if ($trm > 0) $commissionUsd = round($commissionCop / $trm, 2);
            }

            $categoriesSummary[$classification] = [
            // 🔑 claves que el frontend espera
            'classification_code' => $classification,
            'category' => $this->categoryName($classification),


            // ventas
            'sales_sum_usd' => round($salesUsd, 2),
            'sales_sum_cop' => round($salesCop, 2),

            // presupuesto
            'category_budget_usd_for_user' => $categoryBudgetUsd,

            // cumplimiento
            'pct_user_of_category_budget' => $pctOfCategory,

            // comisión
            'applied_commission_pct' => $participation,
            'commission_sum_usd' => $commissionUsd,
            'commission_sum_cop' => round($commissionCop, 2),

            'qualifies' => $qualifies,
        ];

        }

        // user totals from budget_user_totals
        $userTotals = DB::table('budget_user_totals')->where('budget_id', $budget->id)->where('user_id', $userId)->first();

        $totals = [
            'total_commission_cop' => $userTotals->total_commission_cop ?? 0,
            'total_sales_cop' => $userTotals->total_sales_cop ?? 0,
            'total_sales_usd' => $userTotals->total_sales_usd ?? 0,
            'avg_trm' => $avgTrmForUser,
        ];

        uasort($categoriesSummary, function ($a, $b) {
        return $this->categoryOrder($a['classification_code'])
        <=> $this->categoryOrder($b['classification_code']);
        });

        $user = User::select('id','name')->find($userId);
        return response()->json([
            'active' => true,
            'currency' => 'COP',
            'user' => $user,
            'sales' => $sales,
            'categories' => array_values($categoriesSummary),
            'totals' => $totals,
            'user_budget_usd' => $userBudgetUsd,
            'assigned_turns_for_user' => $assignedToUser,
            'budget' => $budget,
            'tickets' => $userTicketsList,
            'tickets_summary' => $userTicketsSummary,
        ]);
    }

    /**
     * assignTurns: update budget_user_turns and refresh the aggregated user totals from category totals (fast).
     */
    public function assignTurns(Request $request, $userId, $budgetId)
    {
        $budget = $this->resolveBudget($request, (int)$budgetId);

        $totalTurns = $budget->total_turns ?? $this->TOTAL_TURNS;

        $data = $request->validate([
            'assigned_turns' => ['required', 'integer', 'min:0']
        ]);

        $newValue = (int) $data['assigned_turns'];

        $totalAssignedExcept = DB::table('budget_user_turns')
            ->where('budget_id', $budget->id)
            ->where('user_id', '!=', $userId)
            ->sum('assigned_turns');

        if ($totalAssignedExcept + $newValue > $totalTurns) {
            return response()->json([
                'message' => 'No hay suficientes turnos disponibles',
                'available' => max(0, $totalTurns - $totalAssignedExcept)
            ], 422);
        }

        DB::table('budget_user_turns')->updateOrInsert(
            [
                'budget_id' => $budget->id,
                'user_id' => $userId
            ],
            [
                'assigned_turns' => $newValue,
                'updated_at' => now()
            ]
        );

        $totalAssigned = DB::table('budget_user_turns')
            ->where('budget_id', $budget->id)
            ->sum('assigned_turns');

        // --- REFRESH user totals from category aggregates (fast) ---
        $agg = DB::table('budget_user_category_totals')
            ->where('budget_id', $budget->id)
            ->where('user_id', $userId)
            ->selectRaw('COALESCE(SUM(sales_usd),0) AS total_sales_usd, COALESCE(SUM(sales_cop),0) AS total_sales_cop, COALESCE(SUM(commission_cop),0) AS total_commission_cop')
            ->first();

        DB::table('budget_user_totals')->updateOrInsert(
            ['budget_id' => $budget->id, 'user_id' => $userId],
            [
                'total_sales_usd' => $agg->total_sales_usd ?? 0,
                'total_sales_cop' => $agg->total_sales_cop ?? 0,
                'total_commission_cop' => $agg->total_commission_cop ?? 0,
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Turnos asignados',
            'assigned_for_user' => $newValue,
            'turns' => [
                'total' => $totalTurns,
                'assigned_total' => $totalAssigned,
                'remaining' => max(0, $totalTurns - $totalAssigned)
            ]
        ]);
    }


    /**
 * Exportar comisiones a Excel (Sellers + Categories) para un presupuesto.
 */
public function exportExcel(Request $request)
{
    // Reutilizamos la lógica existente: llamamos al reporte rápido (bySeller) y tomamos la payload JSON
    $response = $this->bySeller($request);

    // $response es un JsonResponse; convertimos el contenido a array
    $content = $response->getContent();
    $data = json_decode($content, true);

    if (!$data || !isset($data['active']) || !$data['active']) {
        return response()->json(['message' => 'No hay datos para exportar'], 422);
    }

    // Preparar arrays planos para exportar
    $sellers = [];
    foreach ($data['sellers'] as $s) {
        $sellers[] = [
            $s['user_id'] ?? null,
            $s['seller'] ?? null,
            $s['assignedTurns'] ?? 0,
            $s['total_sales_cop'] ?? 0,
            $s['total_sales_usd'] ?? 0,
            $s['total_commission_cop'] ?? 0,
            $s['avg_trm'] ?? null,
            $s['tickets']['tickets_count'] ?? null,
            $s['tickets']['avg_ticket_usd'] ?? null,
            $s['tickets']['avg_ticket_cop'] ?? null,
        ];
    }

    $categories = [];
    foreach ($data['categories_summary'] as $c) {
        $categories[] = [
            $c['classification'] ?? null,
            $c['participation_pct'] ?? null,
            $c['category_budget_usd'] ?? null,
            $c['sales_usd'] ?? null,
            $c['sales_cop'] ?? null,
            $c['pct_of_category'] ?? null,
            $c['qualifies'] ? 'Sí' : 'No',
            $c['applied_commission_pct'] ?? null,
            $c['projected_commission_usd'] ?? $c['commission_usd'] ?? null,
            $c['commission_cop'] ?? null,
        ];
    }

    $budgetId = $data['budget']['id'] ?? 'unknown';
    $filename = "commissions_budget_{$budgetId}_" . date('Ymd_His') . ".xlsx";

    return Excel::download(new CommissionReportExport($sellers, $categories, [
        'budget' => $data['budget'] ?? null,
        'progress' => $data['progress'] ?? null
    ]), $filename);
}




    /**
 * Exportar detalle de comisiones por vendedor a Excel.
 */

public function exportSellerDetail(Request $request, $userId)
{
    $response = $this->bySellerDetail($request, $userId);
    $data = json_decode($response->getContent(), true);

    if (!$data || empty($data['sales'])) {
        return response()->json(['message' => 'No hay datos para exportar'], 422);
    }

    $avgTrm = $data['totals']['avg_trm'] ?? 1;

    // 🔹 Categorías
    $categories = [];
    foreach ($data['categories'] as $c) {
        $categories[] = [
            $c['category'],
            $c['sales_sum_usd'],
            $c['sales_sum_cop'],
            $c['category_budget_usd_for_user'],
            $c['pct_user_of_category_budget'],
            $c['applied_commission_pct'],
            $c['commission_sum_usd'],
            $c['commission_sum_cop'],
        ];
    }

    // 🔹 Ventas (comisión calculada igual que frontend)
    $sales = [];
    foreach ($data['sales'] as $s) {
        $cat = collect($data['categories'])->firstWhere(
            'classification_code',
            (string) $s['category_code']
        );

        $pct = $cat['applied_commission_pct'] ?? 0;

        $commissionCop =
            $s['amount_cop'] > 0
                ? $s['amount_cop'] * ($pct / 100)
                : ($s['value_usd'] * $avgTrm) * ($pct / 100);

        $sales[] = [
            $s['sale_date'],
            $s['folio'],
            $s['product'],
            $cat['category'] ?? 'Sin categoría',
            $s['value_usd'],
            $s['amount_cop'],
            round($commissionCop),
            'Provisional',
        ];
    }

    $filename = 'commission_detail_user_' . $userId . '_' . date('Ymd_His') . '.xlsx';

    return Excel::download(
        new CommissionSellerDetailExport(
            $categories,
            $sales,
            ['user' => $data['user'], 'budget' => $data['budget']]
        ),
        $filename
    );
}


}
