<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\Commission;
use App\Models\Sale;
use App\Models\User;
use App\Models\Category;
use App\Models\CategoryCommission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CommissionReportController extends Controller
{
    // fallback total turns
    protected int $TOTAL_TURNS = 315;

    // fragancias handling
    const FRAG_KEY = 'fragancias';
    const FRAG_CODES = [10, 11, 12];

    protected function resolveBudget(Request $request): Budget
    {
        $budgetId = $request->query('budget_id');
        if ($budgetId) {
            $budget = Budget::find($budgetId);
            abort_if(!$budget, 404, "Presupuesto {$budgetId} no encontrado");
            return $budget;
        }

        $month = $request->query('month');
        if ($month) {
            try {
                $dt = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            } catch (\Throwable $e) {
                abort(422, "Formato de 'month' inválido. Use YYYY-MM (ej: 2026-01).");
            }

            $start = $dt->toDateString();
            $end = $dt->copy()->endOfMonth()->toDateString();

            $budget = Budget::where('start_date', '<=', $end)
                ->where('end_date', '>=', $start)
                ->first();

            abort_if(!$budget, 404, "No hay presupuesto para {$month}. Crea uno en BudgetController.");
            return $budget;
        }

        $today = Carbon::today()->toDateString();

        $budget = Budget::where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->first();

        abort_if(!$budget, 404, "No hay presupuesto activo para la fecha de hoy.");
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

    public function bySeller(Request $request)
    {
        $budget = $this->resolveBudget($request);
        $totalTurns = $budget->total_turns ?? $this->TOTAL_TURNS;
        $roleName = $request->query('role_name');

        $caseFrag = $this->getSqlClassificationCase();

        // ventas por clasificación (USD / COP)
        $categorySalesColl = Sale::selectRaw("
                {$caseFrag} AS classification,
                SUM(COALESCE(sales.value_usd,0)) AS sales_usd,
                SUM(COALESCE(sales.amount_cop,0)) AS sales_cop
            ")
            ->leftJoin('products', 'sales.product_id', '=', 'products.id')
            ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date])
            ->groupBy(DB::raw($caseFrag))
            ->get();

        // COMISIONES por clasificación (COP) - ADICIONADO: para poder mostrar commission_cop en el resumen de categorías
        $categoryCommissionsColl = Commission::selectRaw("
                {$caseFrag} AS classification,
                SUM(COALESCE(commissions.commission_amount,0)) AS commission_cop
            ")
            ->join('sales', 'commissions.sale_id', '=', 'sales.id')
            ->leftJoin('products', 'sales.product_id', '=', 'products.id')
            ->where('commissions.budget_id', $budget->id)
            ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date])
            ->groupBy(DB::raw($caseFrag))
            ->get();

        $categoriesModel = Category::select('id', 'classification_code', 'participation_pct')->get();

        // mapear ids y participaciones por clave normalizada
        $categoryIdByCode = [];
        $participationByCode = [];

        foreach ($categoriesModel as $c) {
            $key = $this->normalizeClassification($c->classification_code);

            if (!isset($categoryIdByCode[$key])) {
                $categoryIdByCode[$key] = $c->id;
            }

            if (!isset($participationByCode[$key])) {
                $participationByCode[$key] = 0.0;
            }
            $participationByCode[$key] += (float)($c->participation_pct ?? 0.0);
        }

        // armar mapas de resultados
        $categorySales = [];
        foreach ($categorySalesColl as $r) {
            $key = $this->normalizeClassification($r->classification);
            $salesUsd = (float)$r->sales_usd;
            $salesCop = (float)$r->sales_cop;

            if (!isset($categorySales[$key])) {
                $categorySales[$key] = (object)[
                    'classification' => $key,
                    'sales_usd' => $salesUsd,
                    'sales_cop' => $salesCop
                ];
            } else {
                $categorySales[$key]->sales_usd += $salesUsd;
                $categorySales[$key]->sales_cop += $salesCop;
            }
        }

        // map comisiones por clasificación (COP)
        $categoryCommissions = [];
        foreach ($categoryCommissionsColl as $r) {
            $key = $this->normalizeClassification($r->classification);
            $categoryCommissions[$key] = (float)$r->commission_cop;
        }

        // rates keyed by category id (raw fetch)
        $ratesByCategoryId = [];
        if ($roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if ($roleId) {
                $rates = CategoryCommission::where('role_id', $roleId)
                    ->whereIn('category_id', array_values($categoryIdByCode))
                    ->get()
                    ->keyBy('category_id');

                foreach ($rates as $catId => $r) {
                    $ratesByCategoryId[$catId] = [
                        'commission_percentage' => is_null($r->commission_percentage) ? null : (float)$r->commission_percentage,
                        'commission_percentage100' => is_null($r->commission_percentage100) ? null : (float)$r->commission_percentage100,
                        'commission_percentage120' => is_null($r->commission_percentage120) ? null : (float)$r->commission_percentage120,
                    ];
                }
            }
        }

        // --- BUILD ratesByGroup (normalized) from ratesByCategoryId + categoriesModel ---
        $ratesByGroup = [];
        foreach ($categoriesModel as $c) {
            $group = (is_numeric($c->classification_code) && in_array((int)$c->classification_code, self::FRAG_CODES, true))
                ? self::FRAG_KEY
                : $this->normalizeClassification($c->classification_code);

            $catId = $c->id;
            if (!isset($ratesByCategoryId[$catId])) continue;

            $raw = $ratesByCategoryId[$catId];

            // normalize to unified keys base/pct100/pct120
            $norm = [
                'base' => $raw['commission_percentage'] ?? null,
                'pct100' => $raw['commission_percentage100'] ?? null,
                'pct120' => $raw['commission_percentage120'] ?? null,
            ];

            if (!isset($ratesByGroup[$group])) {
                $ratesByGroup[$group] = $norm;
            } else {
                // merge taking highest available value per key (prefer more generous)
                foreach (['base','pct100','pct120'] as $k) {
                    if (!is_null($norm[$k]) && (is_null($ratesByGroup[$group][$k]) || $norm[$k] > $ratesByGroup[$group][$k])) {
                        $ratesByGroup[$group][$k] = $norm[$k];
                    }
                }
            }
        }

        $getRatesForClassification = function ($classification) use ($categoryIdByCode, $ratesByCategoryId, $ratesByGroup) {
            // prefer group-level if exists
            if (isset($ratesByGroup[$classification])) {
                return $ratesByGroup[$classification];
            }
            // fallback to single category id mapping
            $catId = $categoryIdByCode[$classification] ?? null;
            if ($catId && isset($ratesByCategoryId[$catId])) {
                $r = $ratesByCategoryId[$catId];
                return [
                    'base' => $r['commission_percentage'] ?? null,
                    'pct100' => $r['commission_percentage100'] ?? null,
                    'pct120' => $r['commission_percentage120'] ?? null,
                ];
            }
            return [];
        };

        $categoriesSummary = [];
        foreach ($categorySales as $classification => $row) {
            $participation = isset($participationByCode[$classification]) ? (float)$participationByCode[$classification] : 0.0;
            $categoryBudgetUsd = round($budget->target_amount * ($participation / 100), 2);
            $salesUsd = (float)$row->sales_usd;
            $salesCop = (float)$row->sales_cop;
            $pctOfCategory = $categoryBudgetUsd > 0 ? round(($salesUsd / $categoryBudgetUsd) * 100, 2) : null;
            $qualifies = ($pctOfCategory !== null) && ($pctOfCategory >= $budget->min_pct_to_qualify);

            $rates = $getRatesForClassification($classification) ?? [];
            $basePct = $rates['base'] ?? null;
            $pct100 = $rates['pct100'] ?? null;
            $pct120 = $rates['pct120'] ?? null;

            $appliedPct = 0.0;
            if (is_null($pctOfCategory) || $pctOfCategory < $budget->min_pct_to_qualify) {
                $appliedPct = 0.0;
            } else {
                if ($pctOfCategory >= 120) {
                    $appliedPct = $pct120 ?? $pct100 ?? $basePct ?? 0.0;
                } elseif ($pctOfCategory >= 100) {
                    $appliedPct = $pct100 ?? $basePct ?? 0.0;
                } else {
                    $appliedPct = $basePct ?? 0.0;
                }
            }

            $projectedCommissionUsd = null;
            if ($salesUsd > 0) {
                $projectedCommissionUsd = round($salesUsd * ($appliedPct / 100), 2);
            }

            // obtener comisión real por clasificación (COP) desde el mapa que calculamos
            $commissionCop = $categoryCommissions[$classification] ?? 0;

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
                'applied_commission_pct' => $appliedPct,
                'projected_commission_usd' => $projectedCommissionUsd,
                'commission_cop' => round($commissionCop, 2),
                'commission_usd' => $commissionUsd,
            ];
        }

        // SELLERS block
        $query = User::query()
            ->selectRaw("
                users.id AS user_id,
                users.name AS seller,
                COALESCE(users.assigned_turns, 0) AS assignedTurns,
                COUNT(DISTINCT sales.id) AS sales_count,
                COALESCE(SUM(sales.amount_cop), 0) AS total_sales_cop,
                COALESCE(SUM(sales.value_usd), 0) AS total_sales_usd,
                COALESCE(SUM(commissions.commission_amount), 0) AS total_commission_cop,
                AVG(sales.exchange_rate) AS avg_trm
            ")
            ->leftJoin('sales', function ($join) use ($budget) {
                $join->on('sales.seller_id', '=', 'users.id')
                    ->whereBetween('sales.sale_date', [
                        $budget->start_date,
                        $budget->end_date
                    ]);
            })
            ->leftJoin('commissions', function ($join) use ($budget) {
                // agregar la condición de budget_id dentro del ON para sumar correctamente las comisiones
                $join->on('commissions.sale_id', '=', 'sales.id')
                     ->where('commissions.budget_id', $budget->id)
                     ->on('commissions.user_id', '=', 'users.id');
            })
            ->groupBy('users.id', 'users.name', 'users.assigned_turns')
            ->orderByDesc('total_sales_cop');

        if ($roleName) {
            // FIX: corregir parámetros de COALESCE para comprobar solapamiento con el presupuesto
            $query->join('user_roles', function ($join) use ($budget) {
                    $join->on('user_roles.user_id', '=', 'users.id')
                        ->whereRaw("user_roles.start_date <= ?", [$budget->end_date])
                        // COALESCE(end_date, budget_end) >= budget_start
                        ->whereRaw("COALESCE(user_roles.end_date, ?) >= ?", [$budget->end_date, $budget->start_date]);
                })
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('roles.name', $roleName);
        }

        $rows = $query->get();

        // avg_trm per user using trms table (unchanged)...
        if ($rows->isNotEmpty()) {
            $userIds = $rows->pluck('user_id')->unique()->values()->all();

            $saleDatesPerUser = Sale::query()
                ->whereIn('seller_id', $userIds)
                ->whereBetween('sale_date', [$budget->start_date, $budget->end_date])
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
                    $r->avg_trm = isset($r->avg_trm) ? round((float)$r->avg_trm, 2) : null;
                }
                return $r;
            });
        }

        // global progress (unchanged)
        $totalUsd = Sale::whereBetween('sale_date', [
            $budget->start_date,
            $budget->end_date
        ])->sum(DB::raw('COALESCE(value_usd,0)'));

        $pct = $budget->target_amount > 0
            ? round(($totalUsd / $budget->target_amount) * 100, 2)
            : 0;

        $isProvisionalGlobal = $pct < $budget->min_pct_to_qualify;

        $requiredUsd = round($budget->target_amount * ($budget->min_pct_to_qualify / 100), 2);
        $missingUsd = max(0, round($requiredUsd - $totalUsd, 2));

        $totalAssigned = (int) User::sum(DB::raw('COALESCE(assigned_turns,0)'));
        $remainingTurns = max(0, $totalTurns - $totalAssigned);

        return response()->json([
            'active' => true,
            'currency' => 'COP',
            'budget' => [
                'id' => $budget->id,
                'name' => $budget->name,
                'start_date' => $budget->start_date,
                'end_date' => $budget->end_date,
                'target_amount' => $budget->target_amount,
                'min_pct_to_qualify' => $budget->min_pct_to_qualify,
                'total_turns' => $budget->total_turns
            ],
            'progress' => [
                'pct' => $pct,
                'min_pct' => $budget->min_pct_to_qualify,
                'missing_usd' => $missingUsd,
                'is_provisional_global' => $isProvisionalGlobal,
                'total_usd' => round($totalUsd, 2),
                'required_usd' => $requiredUsd
            ],
            'categories_summary' => array_values($categoriesSummary),
            'turns' => [
                'total' => $totalTurns,
                'assigned_total' => $totalAssigned,
                'remaining' => $remainingTurns,
            ],
            'sellers' => $rows
        ]);
    }

    public function bySellerDetail(Request $request, $userId)
    {
        $budget = $this->resolveBudget($request);
        $totalTurns = $budget->total_turns ?? $this->TOTAL_TURNS;

        $sales = Commission::select(
                'commissions.id as commission_id',
                'commissions.commission_amount',
                'commissions.is_provisional',
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
            ->join('sales', 'commissions.sale_id', '=', 'sales.id')
            ->leftJoin('products', 'sales.product_id', '=', 'products.id')
            ->where('commissions.user_id', $userId)
            ->where('commissions.budget_id', $budget->id)
            ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date])
            ->orderBy('sales.sale_date')
            ->get();

        $sales->transform(function ($s) {
            $s->category_code = (string)($s->category_code ?? 'sin_categoria');
            return $s;
        });

        $saleDates = $sales->pluck('sale_date')->unique()->values()->all();
        $avgTrmForUser = null;
        if (!empty($saleDates)) {
            $trmRows = DB::table('trms')
                ->select('date', DB::raw('AVG(value) as avg_value'))
                ->whereIn('date', $saleDates)
                ->groupBy('date')
                ->get();

            $trmValues = [];
            foreach ($trmRows as $t) {
                $trmValues[] = (float)$t->avg_value;
            }

            if (!empty($trmValues)) {
                $avgTrmForUser = round(array_sum($trmValues) / count($trmValues), 2);
            }
        }

        $userCategoriesRaw = $sales
            ->groupBy(function ($row) {
                $code = (string)($row->category_code ?? 'sin_categoria');
                if (is_numeric($code) && in_array((int)$code, self::FRAG_CODES, true)) {
                    return self::FRAG_KEY;
                }
                $norm = $this->normalizeClassification($code);
                return $norm;
            })
            ->map(function ($rows, $group) {
                $first = $rows->first();
                return (object)[
                    'classification_code' => $group,
                    'category' => $group === self::FRAG_KEY ? 'FRAGANCIAS' : ($first->category_desc ?? $group),
                    'sales_sum_usd' => $rows->sum(fn($r) => (float)$r->value_usd),
                    'sales_sum_cop' => $rows->sum(fn($r) => (float)$r->amount_cop),
                    'commission_sum_cop' => $rows->sum(fn($r) => (float)$r->commission_amount),
                    'sales_count' => $rows->count(),
                ];
            })
            ->values();

        $caseFrag = $this->getSqlClassificationCase();

        $categorySalesColl = Sale::selectRaw("
                {$caseFrag} AS classification,
                SUM(COALESCE(sales.value_usd,0)) AS sales_usd,
                SUM(COALESCE(sales.amount_cop,0)) AS sales_cop
            ")
            ->leftJoin('products', 'sales.product_id', '=', 'products.id')
            ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date])
            ->groupBy(DB::raw($caseFrag))
            ->get();

        $categoryCommissionsColl = Commission::selectRaw("
                {$caseFrag} AS classification,
                SUM(COALESCE(commissions.commission_amount,0)) AS commission_cop
            ")
            ->join('sales', 'commissions.sale_id', '=', 'sales.id')
            ->leftJoin('products', 'sales.product_id', '=', 'products.id')
            ->where('commissions.budget_id', $budget->id)
            ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date])
            ->groupBy(DB::raw($caseFrag))
            ->get();

        $categorySales = [];
        foreach ($categorySalesColl as $r) {
            $key = (string)$r->classification;
            $categorySales[$key] = [
                'sales_usd' => (float)$r->sales_usd,
                'sales_cop' => (float)$r->sales_cop,
            ];
        }

        $categoryCommissions = [];
        foreach ($categoryCommissionsColl as $r) {
            $key = (string)$r->classification;
            $categoryCommissions[$key] = (float)$r->commission_cop;
        }

        $categoriesModel = Category::select('id', 'classification_code', 'participation_pct')->get();
        $categoryGroupMap = [];
        foreach ($categoriesModel as $c) {
            $code = (string)$c->classification_code;
            $group = (is_numeric($code) && in_array((int)$code, self::FRAG_CODES, true))
                ? self::FRAG_KEY
                : $this->normalizeClassification($code);

            if (!isset($categoryGroupMap[$group])) {
                $categoryGroupMap[$group] = [
                    'category_id' => $c->id,
                    'participation_pct' => (float)$c->participation_pct,
                ];
            } else {
                $categoryGroupMap[$group]['participation_pct'] += (float)$c->participation_pct;
            }
        }

        $categoriesSummary = [];
        foreach ($categorySales as $classification => $row) {
            $participation = $categoryGroupMap[$classification]['participation_pct'] ?? 0.0;
            $categoryBudgetUsd = round($budget->target_amount * ($participation / 100), 2);

            $salesUsd = $row['sales_usd'];
            $salesCop = $row['sales_cop'];

            $pctOfCategory = $categoryBudgetUsd > 0
                ? round(($salesUsd / $categoryBudgetUsd) * 100, 2)
                : null;

            $qualifies = $pctOfCategory !== null && $pctOfCategory >= $budget->min_pct_to_qualify;

            $commissionCop = $categoryCommissions[$classification] ?? 0;

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

        // user budget
        $assignedToUser = (int) optional(User::find($userId))->assigned_turns;
        $totalTurns = $budget->total_turns ?? $this->TOTAL_TURNS;
        $userBudgetUsd = $totalTurns > 0
            ? round($budget->target_amount * ($assignedToUser / $totalTurns), 2)
            : 0.0;

        // rates by role for user (raw)
        $userRoleId = DB::table('user_roles')
            ->where('user_id', $userId)
            ->whereRaw("start_date <= ?", [$budget->end_date])
            // FIX: COALESCE default debe ser budget->end_date y comparar con budget->start_date
            ->whereRaw("COALESCE(end_date, ?) >= ?", [$budget->end_date, $budget->start_date])
            ->orderByDesc('start_date')
            ->value('role_id');

        $ratesByCategoryId = [];
        if ($userRoleId) {
            $rates = CategoryCommission::where('role_id', $userRoleId)->get();
            foreach ($rates as $r) {
                $ratesByCategoryId[$r->category_id] = [
                    'base' => $r->commission_percentage,
                    'pct100' => $r->commission_percentage100,
                    'pct120' => $r->commission_percentage120,
                ];
            }
        }

        // --- BUILD ratesByGroup for user-level logic ---
        $ratesByGroup = [];
        foreach ($categoriesModel as $c) {
            $group = (is_numeric($c->classification_code) && in_array((int)$c->classification_code, self::FRAG_CODES, true))
                ? self::FRAG_KEY
                : $this->normalizeClassification($c->classification_code);

            $catId = $c->id;
            if (!isset($ratesByCategoryId[$catId])) continue;

            $raw = $ratesByCategoryId[$catId];
            $norm = [
                'base' => $raw['base'] ?? null,
                'pct100' => $raw['pct100'] ?? null,
                'pct120' => $raw['pct120'] ?? null,
            ];

            if (!isset($ratesByGroup[$group])) {
                $ratesByGroup[$group] = $norm;
            } else {
                foreach (['base','pct100','pct120'] as $k) {
                    if (!is_null($norm[$k]) && (is_null($ratesByGroup[$group][$k]) || $norm[$k] > $ratesByGroup[$group][$k])) {
                        $ratesByGroup[$group][$k] = $norm[$k];
                    }
                }
            }
        }

        // assemble user categories with correct rates (use ratesByGroup first)
        $userCategories = $userCategoriesRaw->map(function ($c) use (
            $categoriesSummary,
            $categoryGroupMap,
            $ratesByCategoryId,
            $ratesByGroup,
            $budget,
            $userBudgetUsd
        ) {
            $code = $c->classification_code;
            $summary = $categoriesSummary[$code] ?? null;

            $participation = $categoryGroupMap[$code]['participation_pct'] ?? 0;
            $categoryBudgetUser = round($userBudgetUsd * ($participation / 100), 2);

            $pctUser = $categoryBudgetUser > 0
                ? round(($c->sales_sum_usd / $categoryBudgetUser) * 100, 2)
                : null;

            $catId = $categoryGroupMap[$code]['category_id'] ?? null;

            // prefer group-level rates, fallback to single category_id rates
            $rates = $ratesByGroup[$code] ?? ($catId && isset($ratesByCategoryId[$catId]) ? $ratesByCategoryId[$catId] : []);

            $appliedPct = 0.0;
            if ($pctUser !== null && $pctUser >= $budget->min_pct_to_qualify) {
                if ($pctUser >= 120) {
                    $appliedPct = $rates['pct120'] ?? $rates['pct100'] ?? $rates['base'] ?? 0;
                } elseif ($pctUser >= 100) {
                    $appliedPct = $rates['pct100'] ?? $rates['base'] ?? 0;
                } else {
                    $appliedPct = $rates['base'] ?? 0;
                }
            }

            $commissionProjectedUsd = $c->sales_sum_usd > 0
                ? round($c->sales_sum_usd * ($appliedPct / 100), 2)
                : null;

            // compute real commission USD from commission_sum_cop if possible (prefer real)
            $commissionRealUsd = null;
            if ($c->commission_sum_cop > 0 && $c->sales_sum_usd > 0 && $c->sales_sum_cop > 0) {
                $trm = ($c->sales_sum_cop / $c->sales_sum_usd);
                if ($trm > 0) $commissionRealUsd = round($c->commission_sum_cop / $trm, 2);
            }

            return array_merge((array)$c, [
                'category_budget_usd_for_user' => $categoryBudgetUser,
                'pct_user_of_category_budget' => $pctUser,
                'applied_commission_pct' => $appliedPct,
                'commission_projected_usd' => $commissionProjectedUsd,
                // real converted from COP (if any)
                'commission_sum_usd' => $commissionRealUsd,
                'category_qualified' => $summary['qualifies'] ?? false,
            ]);
        });

        $totals = [
            'total_commission_cop' => $sales->sum(fn($r) => (float)$r->commission_amount),
            'total_sales_cop' => $sales->sum(fn($r) => (float)$r->amount_cop),
            'total_sales_usd' => $sales->sum(fn($r) => (float)$r->value_usd),
            'avg_trm' => $avgTrmForUser ?? $sales->avg('exchange_rate'),
        ];

        return response()->json([
            'active' => true,
            'currency' => 'COP',
            'sales' => $sales,
            'categories' => $userCategories,
            'categories_summary' => array_values($categoriesSummary),
            'totals' => $totals,
            'user_budget_usd' => $userBudgetUsd,
            'assigned_turns_for_user' => $assignedToUser,
            'budget' => $budget,
        ]);
    }

    // assignTurns unchanged...
    public function assignTurns(Request $request, $userId)
    {
        $budget = $this->resolveBudget($request);
        $totalTurns = $budget->total_turns ?? $this->TOTAL_TURNS;

        $data = $request->validate([
            'assigned_turns' => ['required', 'integer', 'min:0']
        ]);

        $user = User::find($userId);
        if (!$user) return response()->json(['message' => 'Usuario no encontrado'], 404);

        $newValue = (int) $data['assigned_turns'];

        $totalAssignedExcept = (int) User::where('id', '!=', $userId)
            ->sum(DB::raw('COALESCE(assigned_turns,0)'));

        if ($totalAssignedExcept + $newValue > $totalTurns) {
            return response()->json([
                'message' => 'No hay suficientes turnos disponibles',
                'available' => max(0, $totalTurns - $totalAssignedExcept)
            ], 422);
        }

        $user->assigned_turns = $newValue;
        $user->save();

        $totalAssigned = (int) User::sum(DB::raw('COALESCE(assigned_turns,0)'));

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
}
