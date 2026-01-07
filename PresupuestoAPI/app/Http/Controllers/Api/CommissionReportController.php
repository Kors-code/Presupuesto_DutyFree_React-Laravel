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

class CommissionReportController extends Controller
{
    // Total general de turnos
    protected int $TOTAL_TURNS = 315;

    // Constante clave para fragancias
    const FRAG_KEY = 'fragancias';
    // códigos (numéricos) de products.classification que pertenecen a fragancias
    const FRAG_CODES = [10, 11, 12];

    /**
     * Normaliza clasificaciones venidas tanto de products.classification como de category.classification_code
     * - maneja números, cadenas con separadores, acentos, mayúsculas, variantes textuales.
     */
    private function normalizeClassification($raw)
    {
        $raw = (string) ($raw ?? '');

        $raw = trim($raw);
        if ($raw === '') return 'sin_categoria';

        $rawLower = mb_strtolower($raw);

        // transliterar a ASCII si es posible
        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT', $rawLower);
            if ($trans !== false) $rawLower = mb_strtolower($trans);
        }

        // limpiar caracteres no alfanuméricos (dejamos espacios y separadores)
        $clean = preg_replace('/[^\p{L}\p{N}\s\|,;\/-]/u', '', $rawLower);
        $clean = trim($clean);
        if ($clean === '') return 'sin_categoria';

        // tokens
        $tokens = preg_split('/[,|;\/\-\s]+/', $clean, -1, PREG_SPLIT_NO_EMPTY);

        // si alguno de los tokens es un código numérico que pertenezca a FRAG_CODES => FRAG_KEY
        foreach ($tokens as $t) {
            if (is_numeric($t)) {
                $n = (int)$t;
                if (in_array($n, self::FRAG_CODES, true)) {
                    return self::FRAG_KEY;
                }
            }
        }

        // variantes textuales que deben mapear a fragancias
        foreach ($tokens as $t) {
            if ($t === 'frag' || $t === 'fragrance' || $t === 'fragancia' ||
                $t === 'fragancias' || $t === 'fragrances' || strpos($t, 'frag') === 0 ||
                strpos($t, 'perf') === 0 // perfumes, perfume, perfumery, etc.
            ) {
                return self::FRAG_KEY;
            }
        }

        // si es single numeric token (no frag) devolver el número como string
        if (count($tokens) === 1 && is_numeric($tokens[0])) {
            return (string)(int)$tokens[0];
        }

        // fallback: devolver la forma limpia completa
        return $clean;
    }

    /**
     * Construye la expresión CASE SQL consistente para unificar fragancias a nivel de consulta.
     * Usa REGEXP para capturar '10','11','12' y compara variantes textuales en LOWER.
     */
    private function getSqlClassificationCase()
    {
        $fragKey = self::FRAG_KEY;

        $fragVariants = [
            'frag', 'fragrance', 'fragancia', 'fragancias', 'fragrances',
            'fragances', 'perfume', 'perfumes'
        ];
        // construir lista SQL "'frag','fragrance',..."
        $quoted = array_map(function ($v) {
            return "'" . addslashes($v) . "'";
        }, $fragVariants);
        $variantsList = implode(',', $quoted);

        // patrón para códigos numéricos 10|11|12
        $codesPattern = implode('|', array_map('intval', self::FRAG_CODES)); // "10|11|12"

        // Usamos CAST(... AS CHAR) para asegurar operaciones textuales
        $case = "CASE
            WHEN (CAST(products.classification AS CHAR) REGEXP '^(?:{$codesPattern})$') THEN '{$fragKey}'
            WHEN LOWER(TRIM(CAST(products.classification AS CHAR))) IN ({$variantsList}) THEN '{$fragKey}'
            -- también capturamos si contiene frag o perf (por seguridad)
            WHEN LOWER(CAST(products.classification AS CHAR)) LIKE '%frag%' THEN '{$fragKey}'
            WHEN LOWER(CAST(products.classification AS CHAR)) LIKE '%perf%' THEN '{$fragKey}'
            ELSE COALESCE(products.classification, 'sin_categoria')
        END";

        return $case;
    }

    public function bySeller(Request $request)
    {
        $today = now()->toDateString();

        $budget = Budget::where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->first();

        if (!$budget) {
            return response()->json([
                'active' => false,
                'message' => 'No hay presupuesto activo'
            ]);
        }

        $roleName = $request->query('role_name');

        // --- SQL CASE para unificación en BD ---
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

        $categoriesModel = Category::select('id', 'classification_code', 'participation_pct')->get();

        $categoryIdByCode = [];
        $participationByCode = [];

        foreach ($categoriesModel as $c) {
            $key = $this->normalizeClassification($c->classification_code);
            if (!isset($categoryIdByCode[$key])) {
                $categoryIdByCode[$key] = $c->id;
            }
            $participationByCode[$key] = (float)$c->participation_pct;
        }

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

        $getRatesForClassification = function ($classification) use ($categoryIdByCode, $ratesByCategoryId) {
            $catId = $categoryIdByCode[$classification] ?? null;
            if ($catId && isset($ratesByCategoryId[$catId])) {
                return $ratesByCategoryId[$catId];
            }
            return null;
        };

        $categoriesSummary = [];
        foreach ($categorySales as $classification => $row) {
            $participation = isset($participationByCode[$classification]) ? (float)$participationByCode[$classification] : 0.0;
            $categoryBudgetUsd = round($budget->target_amount * ($participation / 100), 2);
            $salesUsd = (float)$row->sales_usd;
            $salesCop = (float)$row->sales_cop;
            $pctOfCategory = $categoryBudgetUsd > 0 ? round(($salesUsd / $categoryBudgetUsd) * 100, 2) : null;
            $qualifies = ($pctOfCategory !== null) && ($pctOfCategory >= $budget->min_pct_to_qualify);

            $rates = $getRatesForClassification($classification);
            $basePct = $rates['commission_percentage'] ?? null;
            $pct100 = $rates['commission_percentage100'] ?? null;
            $pct120 = $rates['commission_percentage120'] ?? null;

            $appliedPct = 0.0;
            if (is_null($pctOfCategory) || $pctOfCategory < $budget->min_pct_to_qualify) {
                $appliedPct = 0.0;
            } else {
                if ($pctOfCategory >= 120) {
                    if (!is_null($pct120)) $appliedPct = (float)$pct120;
                    elseif (!is_null($pct100)) $appliedPct = (float)$pct100;
                    elseif (!is_null($basePct)) $appliedPct = (float)$basePct;
                    else $appliedPct = 0.0;
                } elseif ($pctOfCategory >= 100) {
                    if (!is_null($pct100)) $appliedPct = (float)$pct100;
                    elseif (!is_null($basePct)) $appliedPct = (float)$basePct;
                    else $appliedPct = 0.0;
                } else {
                    if (!is_null($basePct)) $appliedPct = (float)$basePct;
                    else $appliedPct = 0.0;
                }
            }

            $projectedCommissionUsd = null;
            if ($salesUsd > 0) {
                $projectedCommissionUsd = round($salesUsd * ($appliedPct / 100), 2);
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
                'commission_cop' => $row->commission_cop ?? 0
            ];
        }

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
            ->leftJoin('commissions', function ($join) {
                $join->on('commissions.sale_id', '=', 'sales.id')
                    ->on('commissions.user_id', '=', 'users.id');
            })
            ->groupBy('users.id', 'users.name', 'users.assigned_turns')
            ->orderByDesc('total_sales_cop');

        if ($roleName) {
            $query->join('user_roles', function ($join) use ($budget) {
                    $join->on('user_roles.user_id', '=', 'users.id')
                        ->whereRaw("user_roles.start_date <= ?", [$budget->end_date])
                        ->whereRaw("COALESCE(user_roles.end_date, ?) >= ?", [$budget->start_date, $budget->start_date]);
                })
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('roles.name', $roleName);
        }

        $rows = $query->get();

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
        $totalTurns = $this->TOTAL_TURNS;
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
                'min_pct_to_qualify' => $budget->min_pct_to_qualify
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
    $today = now()->toDateString();

    $budget = Budget::where('start_date', '<=', $today)
        ->where('end_date', '>=', $today)
        ->first();

    if (!$budget) {
        return response()->json([
            'active' => false,
            'message' => 'No hay presupuesto activo'
        ]);
    }

    /* ======================================================
     * 1) DETALLE DE VENTAS (10 / 11 / 12 SE CONSERVAN)
     * ====================================================== */
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
        ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date])
        ->orderBy('sales.sale_date')
        ->get();

    // solo visual
    $sales->transform(function ($s) {
        $s->category_code = (string)($s->category_code ?? 'sin_categoria');
        return $s;
    });

    /* ======================================================
     * 2) AGRUPAR CATEGORÍAS DEL USUARIO
     *    10 / 11 / 12 => fragancias
     * ====================================================== */
    $userCategoriesRaw = $sales
        ->groupBy(function ($row) {
            $code = (string)($row->category_code ?? 'sin_categoria');

            if (is_numeric($code) && in_array((int)$code, self::FRAG_CODES, true)) {
                return self::FRAG_KEY; // fragancias
            }

            return $code;
        })
        ->map(function ($rows, $group) {
            $first = $rows->first();

            return (object)[
                'classification_code' => $group,
                'category' => $group === self::FRAG_KEY
                    ? 'FRAGANCIAS'
                    : ($first->category_desc ?? $group),

                'sales_sum_usd' => $rows->sum(fn($r) => (float)$r->value_usd),
                'sales_sum_cop' => $rows->sum(fn($r) => (float)$r->amount_cop),
                'commission_sum_cop' => $rows->sum(fn($r) => (float)$r->commission_amount),
                'sales_count' => $rows->count(),
            ];
        })
        ->values();

    /* ======================================================
     * 3) VENTAS GLOBALES POR CATEGORÍA (SQL CASE)
     * ====================================================== */
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

    /* ======================================================
     * 4) MAPA DE CATEGORÍAS BASE (10/11/12 => fragancias)
     * ====================================================== */
    $categoriesModel = Category::select('id', 'classification_code', 'participation_pct')->get();

    $categoryGroupMap = [];
    foreach ($categoriesModel as $c) {
        $code = (string)$c->classification_code;

        if (is_numeric($code) && in_array((int)$code, self::FRAG_CODES, true)) {
            $group = self::FRAG_KEY;
        } else {
            $group = $code;
        }

        if (!isset($categoryGroupMap[$group])) {
            $categoryGroupMap[$group] = [
                'category_id' => $c->id,
                'participation_pct' => (float)$c->participation_pct,
            ];
        }
    }

    /* ======================================================
     * 5) RESUMEN GLOBAL POR CATEGORÍA
     * ====================================================== */
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
            if ($trm > 0) {
                $commissionUsd = round($commissionCop / $trm, 2);
            }
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

    /* ======================================================
     * 6) PRESUPUESTO DEL USUARIO
     * ====================================================== */
    $totalTurns = $this->TOTAL_TURNS;
    $assignedToUser = (int) optional(User::find($userId))->assigned_turns;
    $userBudgetUsd = $totalTurns > 0
        ? round($budget->target_amount * ($assignedToUser / $totalTurns), 2)
        : 0.0;

    /* ======================================================
     * 7) COMISIONES POR ROL
     * ====================================================== */
    $userRoleId = DB::table('user_roles')
        ->where('user_id', $userId)
        ->whereRaw("start_date <= ?", [$budget->end_date])
        ->whereRaw("COALESCE(end_date, ?) >= ?", [$budget->start_date, $budget->start_date])
        ->orderByDesc('start_date')
        ->value('role_id');

    $ratesByCategoryId = [];
    if ($userRoleId) {
        $rates = CategoryCommission::where('role_id', $userRoleId)
            ->get();

        foreach ($rates as $r) {
            $ratesByCategoryId[$r->category_id] = [
                'base' => $r->commission_percentage,
                'pct100' => $r->commission_percentage100,
                'pct120' => $r->commission_percentage120,
            ];
        }
    }

    /* ======================================================
     * 8) ARMAR CATEGORÍAS DEL USUARIO (FINAL)
     * ====================================================== */
    $userCategories = $userCategoriesRaw->map(function ($c) use (
        $categoriesSummary,
        $categoryGroupMap,
        $ratesByCategoryId,
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

        $rates = $catId && isset($ratesByCategoryId[$catId])
            ? $ratesByCategoryId[$catId]
            : [];

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

        $commissionUsd = $c->sales_sum_usd > 0
            ? round($c->sales_sum_usd * ($appliedPct / 100), 2)
            : null;

        return array_merge((array)$c, [
            'category_budget_usd_for_user' => $categoryBudgetUser,
            'pct_user_of_category_budget' => $pctUser,
            'applied_commission_pct' => $appliedPct,
            'commission_sum_usd' => $commissionUsd,
            'category_qualified' => $summary['qualifies'] ?? false,
        ]);
    });

    /* ======================================================
     * 9) TOTALES
     * ====================================================== */
    $totals = [
        'total_commission_cop' => $sales->sum(fn($r) => (float)$r->commission_amount),
        'total_sales_cop' => $sales->sum(fn($r) => (float)$r->amount_cop),
        'total_sales_usd' => $sales->sum(fn($r) => (float)$r->value_usd),
        'avg_trm' => $sales->avg('exchange_rate'),
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


    public function assignTurns(Request $request, $userId)
    {
        $data = $request->validate([
            'assigned_turns' => ['required', 'integer', 'min:0']
        ]);

        $user = User::find($userId);
        if (!$user) return response()->json(['message' => 'Usuario no encontrado'], 404);

        $newValue = (int) $data['assigned_turns'];

        $totalAssignedExcept = (int) User::where('id', '!=', $userId)
            ->sum(DB::raw('COALESCE(assigned_turns,0)'));

        $totalTurns = $this->TOTAL_TURNS;

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
