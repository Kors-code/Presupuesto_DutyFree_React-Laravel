<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Sale;
use App\Models\Category;
use App\Models\CategoryCommission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class CommissionService
{
    // fallback total turns
    protected int $TOTAL_TURNS = 315;

    // fragancias handling
    const FRAG_KEY = 'fragancias';
    const FRAG_CODES = [10, 11, 12];
    protected int $MIN_PCT_TO_QUALIFY = 80;

    public function generateForBudget(int $budgetId): array
    {
        Log::info('[COMMISSION] Starting generation (by budget)', ['budget_id' => $budgetId]);

        $budget = Budget::find($budgetId);
        if (!$budget) {
            Log::warning('[COMMISSION] Budget not found', ['budget_id' => $budgetId]);
            return ['status' => 'budget_not_found'];
        }

        return $this->processBudget($budget);
    }

    public function generateForActiveBudget(): array
    {
        $today = now()->toDateString();

        Log::info('[COMMISSION] Starting generation (active budget)', ['date' => $today]);

        $budget = Budget::where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->first();

        if (!$budget) {
            Log::warning('[COMMISSION] No active budget found');
            return ['status' => 'no_budget'];
        }

        return $this->processBudget($budget);
    }

    protected function processBudget(Budget $budget): array
    {
        $result = $this->processBudgetForUsers($budget, null);

        return $result + [
            'status' => $result['status'] ?? 'ok'
        ];
    }

    protected function processBudgetForUsers(Budget $budget, ?array $onlyUserIds = null): array
    {
        Log::info('[COMMISSION] processBudgetForUsers', ['budget_id' => $budget->id, 'onlyUserIds' => $onlyUserIds]);

        // 1) total turns: prefer budget.total_turns; si no existe, calcular desde budget_user_turns; sino fallback
        $totalTurns = $budget->total_turns ?? DB::table('budget_user_turns')->where('budget_id', $budget->id)->sum('assigned_turns');
        if (empty($totalTurns) || $totalTurns <= 0) {
            $totalTurns = $this->TOTAL_TURNS;
        }

        // total USD and COP to determine provisional (respect budget_id if column exists)
        $totalUsdQuery = Sale::whereBetween('sale_date', [$budget->start_date, $budget->end_date]);
        $totalCopQuery = Sale::whereBetween('sale_date', [$budget->start_date, $budget->end_date]);

        if (Schema::hasColumn('sales', 'budget_id')) {
            $totalUsdQuery->where('sales.budget_id', $budget->id);
            $totalCopQuery->where('sales.budget_id', $budget->id);
        }

        $totalUsd = (float) $totalUsdQuery->sum(DB::raw('COALESCE(value_usd,0)'));
        $totalCop = (float) $totalCopQuery->sum(DB::raw('COALESCE(amount_cop,0)'));

        $pct = $budget->target_amount > 0 ? ($totalUsd / $budget->target_amount) * 100 : 0;
        $isProvisional = $pct < $this->MIN_PCT_TO_QUALIFY;

        Log::info('[COMMISSION] Budget progress', [
            'total_sales_usd' => round($totalUsd, 2),
            'total_sales_cop' => round($totalCop, 2),
            'pct' => round($pct, 2),
            'is_provisional' => $isProvisional,
            'total_turns' => $totalTurns
        ]);

        DB::beginTransaction();

        try {
            // ventas agregadas por seller + grupo (USD + COP)
            $caseFrag = $this->getSqlClassificationCase();

            $salesByUserGroupQuery = Sale::selectRaw("
                    {$caseFrag} AS classification,
                    sales.seller_id,
                    SUM(COALESCE(sales.value_usd,0)) AS sales_usd,
                    SUM(COALESCE(sales.amount_cop,0)) AS sales_cop
                ")
                ->leftJoin('products','sales.product_id','=','products.id')
                ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date]);

            if (Schema::hasColumn('sales', 'budget_id')) {
                $salesByUserGroupQuery->where('sales.budget_id', $budget->id);
            }

            if (is_array($onlyUserIds) && !empty($onlyUserIds)) {
                $salesByUserGroupQuery->whereIn('sales.seller_id', $onlyUserIds);
            }

            $salesByUserGroupRows = $salesByUserGroupQuery
                ->groupBy(DB::raw($caseFrag), 'sales.seller_id')
                ->get();

            $salesByUserGroup = []; // [user_id][group] => ['sales_usd'=>..., 'sales_cop'=>...]
            $userIds = [];
            foreach ($salesByUserGroupRows as $r) {
                $grp = $this->normalizeClassification($r->classification);
                $uid = (int)$r->seller_id;
                $userIds[$uid] = true;
                $salesByUserGroup[$uid][$grp] = [
                    'sales_usd' => (float)$r->sales_usd,
                    'sales_cop' => (float)$r->sales_cop
                ];
            }

            // 2) categories + participation desde category_commissions según budget
            $categoriesWithParticipation = DB::table('categories as c')
                ->join('category_commissions as cc', function ($join) use ($budget) {
                    $join->on('cc.category_id', '=', 'c.id')
                        ->where('cc.budget_id', $budget->id);
                })
                ->select(
                    'c.id',
                    'c.classification_code',
                    DB::raw('MAX(cc.participation_pct) as participation_pct')
                )
                ->groupBy('c.id', 'c.classification_code')
                ->get();

            $categoryGroupMap = []; // group => ['category_ids'=>[], 'participation_pct'=>SUM]
            foreach ($categoriesWithParticipation as $c) {
                $grp = $this->normalizeClassification($c->classification_code);
                $pct = (float)$c->participation_pct;
                if (!isset($categoryGroupMap[$grp])) {
                    $categoryGroupMap[$grp] = [
                        'category_ids' => [$c->id],
                        'participation_pct' => $pct
                    ];
                } else {
                    $categoryGroupMap[$grp]['category_ids'][] = $c->id;
                    $categoryGroupMap[$grp]['participation_pct'] = $pct;
                }
            }
            Log::info('[COMMISSION] Category groups', ['categoryGroupMap' => $categoryGroupMap]);

            // DEBUG ADICIONAL: listar ids y classification_code que mapearon a fragancias
$fragCategoryIds = [];
$fragCategoryRaw = [];
foreach ($categoriesWithParticipation as $c) {
    $grp = $this->normalizeClassification($c->classification_code);
    if ($grp === self::FRAG_KEY) {
        $fragCategoryIds[] = $c->id;
        $fragCategoryRaw[$c->id] = $c->classification_code;
    }
}
Log::info('[COMMISSION] Frag mapping debug', [
    'frag_category_ids' => $fragCategoryIds,
    'frag_category_raw_codes' => $fragCategoryRaw
]);


            // 3) assigned_turns
            $assignedTurnsByUser = [];
            if (!empty($userIds)) {
                $assignedRows = DB::table('budget_user_turns')
                    ->where('budget_id', $budget->id)
                    ->whereIn('user_id', array_keys($userIds))
                    ->pluck('assigned_turns', 'user_id'); // [user_id => assigned_turns]

                foreach ($userIds as $uid => $_) {
                    $assignedTurnsByUser[$uid] = (int)($assignedRows[$uid] ?? 0);
                }
            }

            // 4) pctUserByGroup
            $pctUserByGroup = [];
            foreach ($assignedTurnsByUser as $uid => $assigned) {
                $userBudgetUsd = $totalTurns > 0 ? round($budget->target_amount * ($assigned / $totalTurns), 2) : 0.0;

                foreach ($categoryGroupMap as $grp => $meta) {
                    $participation = $meta['participation_pct'] ?? 0;
                    $categoryBudgetForUser = $userBudgetUsd * ($participation / 100);

                    $salesUsd = $salesByUserGroup[$uid][$grp]['sales_usd'] ?? 0.0;
                    $salesCop = $salesByUserGroup[$uid][$grp]['sales_cop'] ?? 0.0;

                    if ($categoryBudgetForUser > 0) {
                        $pctVal = round(($salesUsd / $categoryBudgetForUser) * 100, 2);
                    } else {
                        $pctVal = null;
                    }
                    $pctUserByGroup[$uid][$grp] = [
                        'pct' => $pctVal,
                        'category_budget_for_user' => $categoryBudgetForUser,
                        'sales_usd' => $salesUsd,
                        'sales_cop' => $salesCop
                    ];
                }
            }

            // DEBUG: log resumido para inspección rápida
            Log::info('[COMMISSION] Debug snapshot', [
                'users_count' => count($userIds),
                'sample_userIds' => array_slice(array_keys($userIds), 0, 6),
                'category_groups' => array_keys($categoryGroupMap),
                'assignedTurnsByUser_sample' => array_slice($assignedTurnsByUser, 0, 8)
            ]);

            // 5) build ratesByGroupByRole and rules map
            $allCategoryIds = collect($categoriesWithParticipation)->pluck('id')->all();
            if (empty($allCategoryIds)) {
                // si no hay categories en este budget, no hay nada que hacer
                Log::warning('[COMMISSION] No categories found for budget', ['budget_id' => $budget->id]);
                DB::commit();
                return [
                    'status' => 'ok',
                    'users_processed' => 0,
                    'total_sales_usd' => round($totalUsd, 2),
                    'total_sales_cop' => round($totalCop, 2),
                    'pct' => round($pct, 2),
                    'is_provisional' => $isProvisional,
                ];
            }

            // IMPORTANT: filtrar CategoryCommission por budget_id (FIX)
            $categoryCommissions = CategoryCommission::whereIn('category_id', $allCategoryIds)
                ->where('budget_id', $budget->id)
                ->get();

            // map category_id -> group
            $categoryIdToGroup = [];
            foreach ($categoriesWithParticipation as $c) {
                $categoryIdToGroup[$c->id] = $this->normalizeClassification($c->classification_code);
            }

            $ratesByGroupByRole = []; // [role_id][group] => ['base','pct100','pct120']
            $ruleByRoleCategory = [];  // [role_id][category_id] => ruleModel
            foreach ($categoryCommissions as $row) {
                $roleId = (int)$row->role_id;
                $catId = (int)$row->category_id;
                $group = $categoryIdToGroup[$catId] ?? null;
                if (!$group) continue;

                // store rule model for potential rule selection
                $ruleByRoleCategory[$roleId][$catId] = $row;

                if (!isset($ratesByGroupByRole[$roleId][$group])) {
                    $ratesByGroupByRole[$roleId][$group] = [
                        'base' => $row->commission_percentage,
                        'pct100' => $row->commission_percentage100,
                        'pct120' => $row->commission_percentage120,
                    ];
                } else {
                    // prefer most generous per slot
                    foreach (['base','pct100','pct120'] as $k) {
                        $val = $k === 'base' ? $row->commission_percentage : ($k === 'pct100' ? $row->commission_percentage100 : $row->commission_percentage120);
                        if (!is_null($val) && (is_null($ratesByGroupByRole[$roleId][$group][$k]) || $val > $ratesByGroupByRole[$roleId][$group][$k])) {
                            $ratesByGroupByRole[$roleId][$group][$k] = $val;
                        }
                    }
                }
            }

            // DEBUG: verificación rápida de reglas
            Log::info('[COMMISSION] Rules snapshot', [
                'categories_count' => count($allCategoryIds),
                'category_commissions_count' => $categoryCommissions->count(),
                'rates_preview' => array_slice($ratesByGroupByRole, 0, 6)
            ]);

            // 6) compute and upsert
            $usersProcessed = [];
            foreach ($pctUserByGroup as $uid => $groups) {
                $userTotalsSalesUsd = 0.0;
                $userTotalsSalesCop = 0.0;
                $userTotalsCommissionCop = 0.0;

                $userModel = User::find($uid);
                $userRole = $this->resolveRoleModelForUserAtDate($userModel, $budget->end_date);
                $roleId = $userRole ? (int)$userRole->id : null;

                foreach ($groups as $grp => $entry) {
                    $salesUsd = (float)($entry['sales_usd'] ?? 0.0);
                    $salesCop = (float)($entry['sales_cop'] ?? 0.0);
                    $pctUser = $entry['pct'];

                    // find rates for role+group
                    $rates = $roleId ? ($ratesByGroupByRole[$roleId][$grp] ?? null) : null;
                    if (!$rates) {
                        // fallback: find any rule for a category in the group for this role
                        $possibleCatIds = $categoryGroupMap[$grp]['category_ids'] ?? [];
                        $foundRule = null;
                        foreach ($possibleCatIds as $cid) {
                            if (isset($ruleByRoleCategory[$roleId][$cid])) {
                                $foundRule = $ruleByRoleCategory[$roleId][$cid];
                                break;
                            }
                        }
                        if ($foundRule) {
                            $rates = [
                                'base' => $foundRule->commission_percentage,
                                'pct100' => $foundRule->commission_percentage100,
                                'pct120' => $foundRule->commission_percentage120,
                            ];
                        }
                    }

                    if (!$rates) {
                        Log::debug('[COMMISSION] no rates for role+group', ['user_id' => $uid, 'group' => $grp]);
                        $appliedPct = 0.0;
                    } else {
                        $appliedPct = 0.0;
                        if (!is_null($pctUser) && $pctUser >= $this->MIN_PCT_TO_QUALIFY) {
                            if ($pctUser >= 120) {
                                $appliedPct = $rates['pct120'] ?? $rates['pct100'] ?? $rates['base'] ?? 0.0;
                            } elseif ($pctUser >= 100) {
                                $appliedPct = $rates['pct100'] ?? $rates['base'] ?? 0.0;
                            } else {
                                $appliedPct = $rates['base'] ?? 0.0;
                            }
                        } // else remains 0
                    }

                    // compute commission_cop
                    $commissionCop = 0.0;
                    if ($salesCop > 0) {
                        $commissionCop = round($salesCop * ((float)$appliedPct / 100), 2);
                    } elseif ($salesUsd > 0) {
                        $trmGlobal = ($totalUsd > 0 && $totalCop > 0) ? ($totalCop / $totalUsd) : null;
                        if ($trmGlobal && $trmGlobal > 0) {
                            $commissionCop = round(($salesUsd * ((float)$appliedPct / 100)) * $trmGlobal, 2);
                        } else {
                            $commissionCop = 0.0;
                        }
                    }

                    DB::table('budget_user_category_totals')->updateOrInsert(
                        [
                            'budget_id' => $budget->id,
                            'user_id' => $uid,
                            'category_group' => $grp,
                        ],
                        [
                            'sales_usd' => $salesUsd,
                            'sales_cop' => $salesCop,
                            'commission_cop' => $commissionCop,
                            'applied_pct' => $appliedPct,
                            'updated_at' => now(),
                        ]
                    );

                    $userTotalsSalesUsd += $salesUsd;
                    $userTotalsSalesCop += $salesCop;
                    $userTotalsCommissionCop += $commissionCop;
                } // end groups for user

                DB::table('budget_user_totals')->updateOrInsert(
                    ['budget_id' => $budget->id, 'user_id' => $uid],
                    [
                        'total_sales_usd' => $userTotalsSalesUsd,
                        'total_sales_cop' => $userTotalsSalesCop,
                        'total_commission_cop' => $userTotalsCommissionCop,
                        'updated_at' => now(),
                    ]
                );

                $usersProcessed[] = $uid;
            } // end users loop

            DB::commit();

            Log::info('[COMMISSION] Finished processBudgetForUsers (aggregated mode)', [
                'users_processed' => count($usersProcessed),
                'pct' => round($pct,2),
                'is_provisional' => $isProvisional,
            ]);

            return [
                'status' => 'ok',
                'users_processed' => count($usersProcessed),
                'total_sales_usd' => round($totalUsd, 2),
                'total_sales_cop' => round($totalCop, 2),
                'pct' => round($pct, 2),
                'is_provisional' => $isProvisional,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[COMMISSION] Fatal error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    // recalcForUserAndBudget unchanged...
    public function recalcForUserAndBudget(int $userId, int $budgetId): void
    {
        Log::info('[COMMISSION] Recalc aggregated for user+budget', ['user_id' => $userId, 'budget_id' => $budgetId]);

        $budget = Budget::findOrFail($budgetId);

        DB::beginTransaction();
        try {
            DB::table('budget_user_category_totals')->where('budget_id', $budgetId)->where('user_id', $userId)->delete();
            DB::table('budget_user_totals')->where('budget_id', $budgetId)->where('user_id', $userId)->delete();

            $result = $this->processBudgetForUsers($budget, [$userId]);

            Log::info('[COMMISSION] Recalc aggregated finished', ['result' => $result]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[COMMISSION] Recalc failed', [
                'user_id' => $userId,
                'budget_id' => $budgetId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // helpers...
  /**
 * Normaliza classification_code en un "grupo" seguro.
 * - Prioriza extracción NUMÉRICA: si contiene un número y ese número está en FRAG_CODES -> FRAG_KEY.
 * - Si contiene un número distinto, devuelve el número como string ('22').
 * - Si no contiene número, elimina acentos y normaliza espacios/lowercase.
 * - Sólo mapea a FRAG_KEY por nombres exactos/esperados (no por substring simple).
 */
private function normalizeClassification($raw)
{
    $raw = (string) ($raw ?? '');
    $raw = trim($raw);
    if ($raw === '') return 'sin_categoria';

    // eliminar acentos básicos para comparación
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT', $raw);
    $normalized = mb_strtolower(trim($normalized));

    // 1) intentar extraer un número (ej: "10", "10 - Fragancias", "010", "22a")
    if (preg_match('/\b(\d{1,5})\b/', $normalized, $m)) {
        $num = (int)$m[1];
        if (in_array($num, self::FRAG_CODES, true)) {
            return self::FRAG_KEY;
        }
        // devolver el número como string para agrupar por código (ej "22")
        return (string)$num;
    }

    // 2) fallback para valores textuales: solo aceptar nombres frag/perf exactos o muy controlados
    $acceptedFragNames = [
        'fragancias','fragancia','fragancias perfumeria','perfumeria','perfumeria fragancias',
        'fragancias/perfumeria','perfumería','fragancias y perfumería'
    ];
    // normalizar variantes (sin acentos, espacios multiples)
    $clean = preg_replace('/\s+/', ' ', $normalized);

    if (in_array($clean, $acceptedFragNames, true)) {
        return self::FRAG_KEY;
    }

    // 3) devolver texto normalizado (usado como grupo) - sin caracteres especiales repetidos
    $clean = preg_replace('/[^a-z0-9\-_ ]+/', '', $clean);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return trim($clean);
}

/**
 * CASE SQL más estricto:
 * - detecta CÓDIGOS numéricos exactos (10|11|12) dentro del campo products.classification
 * - detecta palabras frag/perf sólo como tokens (no sub-strings arbitrarios)
 * - deja el texto original como fallback
 */
private function getSqlClassificationCase(): string
{
    $codes = implode('|', array_map('intval', self::FRAG_CODES));

    // patrón que detecta el código numérico como token o el número aislado dentro del campo
    $numRegexp = "(^|[^0-9])(?:{$codes})([^0-9]|$)";

    // patrón que detecta frag/perf como palabra (token) - reduce falsos positivos
    $wordRegexp = "(^|[^a-zA-Z0-9])(frag|perf|perfume|perfumeria)([^a-zA-Z0-9]|$)";

    return "CASE
        WHEN CAST(products.classification AS CHAR) REGEXP '{$numRegexp}' THEN '" . self::FRAG_KEY . "'
        WHEN LOWER(CAST(products.classification AS CHAR)) REGEXP '{$wordRegexp}' THEN '" . self::FRAG_KEY . "'
        ELSE TRIM(COALESCE(products.classification, 'sin_categoria'))
    END";
}

    protected function getBaseCop(Sale $sale): float
    {
        if (!empty($sale->amount_cop) && $sale->amount_cop > 0) return (float) $sale->amount_cop;
        if (!empty($sale->value_pesos) && $sale->value_pesos > 0) return (float) $sale->value_pesos;
        if (!empty($sale->amount) && $sale->amount > 0) return (float) $sale->amount;

        if (!empty($sale->value_usd) && $sale->value_usd > 0) {
            $trm = $sale->exchange_rate ?? 0;
            if ($trm && $trm > 0) {
                return (float) round($sale->value_usd * $trm, 2);
            }
        }

        return 0.0;
    }

    protected function resolveCashierUser(Sale $sale): ?User
    {
        if (!empty($sale->cashier_id)) {
            return User::find($sale->cashier_id);
        }

        $text = trim((string) $sale->cashier);
        if ($text === '') return null;

        return User::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($text)])
            ->orWhere('name', 'like', '%' . $text . '%')
            ->first();
    }

    protected function resolveRoleModelForUserAtDate(User $user, $date)
    {
        if (method_exists($user, 'roleAtDate')) {
            $r = $user->roleAtDate($date);
            if ($r instanceof \App\Models\Role) return $r;
            if ($r && isset($r->role)) return $r->role;
        }

        if (method_exists($user, 'roles')) {
            $pivot = $user->roles()->with('role')->orderByDesc('start_date')->first();
            if ($pivot && $pivot->role) return $pivot->role;
        }

        return $user->role ?? null;
    }
}
