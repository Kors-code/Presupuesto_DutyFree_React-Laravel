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

    /**
     * Genera comisiones para un presupuesto específico (delegado a processBudget).
     */
    public function generateForBudget(int $budgetId): array
    {
        Log::info('[COMMISSION] Starting generation (by budget)', [
            'budget_id' => $budgetId
        ]);

        $budget = Budget::find($budgetId);
        if (!$budget) {
            Log::warning('[COMMISSION] Budget not found', ['budget_id' => $budgetId]);
            return ['status' => 'budget_not_found'];
        }

        return $this->processBudget($budget);
    }

    /**
     * Genera comisiones para el presupuesto activo.
     */
    public function generateForActiveBudget(): array
    {
        $today = now()->toDateString();

        Log::info('[COMMISSION] Starting generation (active budget)', [
            'date' => $today
        ]);

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
        // process all users
        $result = $this->processBudgetForUsers($budget, null);

        return $result + [
            'status' => $result['status'] ?? 'ok'
        ];
    }

    /**
     * Procesa un presupuesto, opcionalmente restringido a una lista de usuarios.
     *
     * @param Budget $budget
     * @param int[]|null $onlyUserIds Si null => procesa todos; si array => procesa solo esos usuarios
     *
     * @return array (resumen)
     */
    protected function processBudgetForUsers(Budget $budget, ?array $onlyUserIds = null): array
    {
        Log::info('[COMMISSION] processBudgetForUsers', [
            'budget_id' => $budget->id,
            'onlyUserIds' => $onlyUserIds
        ]);

        $totalTurns = $budget->total_turns ?? $this->TOTAL_TURNS;

        // total USD and COP to determine provisional (respect budget_id if column exists)
        $totalUsdQuery = Sale::whereBetween('sale_date', [$budget->start_date, $budget->end_date]);
        $totalCopQuery = Sale::whereBetween('sale_date', [$budget->start_date, $budget->end_date]);

        if (Schema::hasColumn('sales', 'budget_id')) {
            $totalUsdQuery->where('sales.budget_id', $budget->id);
            $totalCopQuery->where('sales.budget_id', $budget->id);
        }

        $totalUsd = (float) $totalUsdQuery->sum(DB::raw('COALESCE(value_usd,0)'));
        $totalCop = (float) $totalCopQuery->sum(DB::raw('COALESCE(amount_cop,0)'));

        $pct = $budget->target_amount > 0
            ? ($totalUsd / $budget->target_amount) * 100
            : 0;

        $isProvisional = $pct < $this->MIN_PCT_TO_QUALIFY;

        Log::info('[COMMISSION] Budget progress', [
            'total_sales_usd' => round($totalUsd, 2),
            'total_sales_cop' => round($totalCop, 2),
            'pct' => round($pct, 2),
            'is_provisional' => $isProvisional,
        ]);

        DB::beginTransaction();

        try {
            // 1) ventas agregadas por seller + grupo (USD + COP)
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

            // 2) categories model -> group participation & category ids
            $categoriesModel = Category::select('id','classification_code','participation_pct')->get();
            $categoryGroupMap = []; // group => ['category_ids'=>[], 'participation_pct' => sum]
            foreach ($categoriesModel as $c) {
                $grp = $this->normalizeClassification($c->classification_code);
                if (!isset($categoryGroupMap[$grp])) {
                    $categoryGroupMap[$grp] = [
                        'category_ids' => [$c->id],
                        'participation_pct' => (float)$c->participation_pct
                    ];
                } else {
                    $categoryGroupMap[$grp]['category_ids'][] = $c->id;
                    $categoryGroupMap[$grp]['participation_pct'] += (float)$c->participation_pct;
                }
            }

            // 3) obtener assigned_turns de budget_user_turns (NO de users.assigned_turns)
            $assignedTurnsByUser = [];
            if (!empty($userIds)) {
                // fetch assigned_turns for these users in this budget
                $assignedRows = DB::table('budget_user_turns')
                    ->where('budget_id', $budget->id)
                    ->whereIn('user_id', array_keys($userIds))
                    ->pluck('assigned_turns', 'user_id'); // [user_id => assigned_turns]

                foreach ($userIds as $uid => $_) {
                    $assignedTurnsByUser[$uid] = (int)($assignedRows[$uid] ?? 0);
                }
            }

            // 4) calcular pctUserByGroup [user][group] => ['pct','category_budget_for_user','sales_usd','sales_cop']
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

            // 5) build ratesByGroupByRole and rules map
            $allCategoryIds = $categoriesModel->pluck('id')->all();
            $categoryCommissions = CategoryCommission::whereIn('category_id', $allCategoryIds)->get();

            // map category_id -> group
            $categoryIdToGroup = [];
            foreach ($categoriesModel as $c) {
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

            // 6) For each user+group compute appliedPct and upsert into budget_user_category_totals
            $usersProcessed = [];
            foreach ($pctUserByGroup as $uid => $groups) {
                $userTotalsSalesUsd = 0.0;
                $userTotalsSalesCop = 0.0;
                $userTotalsCommissionCop = 0.0;

                // resolve user's role once (prefer at budget end date)
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
                        // no rule found -> skip group for this user
                        Log::debug('[COMMISSION] no rates for role+group', ['user_id' => $uid, 'group' => $grp]);
                        $appliedPct = 0.0;
                    } else {
                        // decide applied percentage
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

                    // compute commission_cop from aggregates (fast)
                    $commissionCop = 0.0;
                    if ($salesCop > 0) {
                        $commissionCop = round($salesCop * ((float)$appliedPct / 100), 2);
                    } elseif ($salesUsd > 0) {
                        // fallback using global trm if available
                        $trmGlobal = ($totalUsd > 0 && $totalCop > 0) ? ($totalCop / $totalUsd) : null;
                        if ($trmGlobal && $trmGlobal > 0) {
                            $commissionCop = round(($salesUsd * ((float)$appliedPct / 100)) * $trmGlobal, 2);
                        } else {
                            // cannot compute in COP -> leave 0 (we prioritized speed)
                            $commissionCop = 0.0;
                        }
                    }

                    // upsert aggregated row per budget,user,group
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

                // update budget_user_totals row for user
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

    /**
     * Recalcula las agregaciones PARA UN usuario + budget:
     * - borra las filas de budget_user_category_totals de ese user+budget
     * - reprocesa las ventas de ese usuario en ese budget (re-inserta desde agregados)
     */
    public function recalcForUserAndBudget(int $userId, int $budgetId): void
    {
        Log::info('[COMMISSION] Recalc aggregated for user+budget', [
            'user_id' => $userId,
            'budget_id' => $budgetId,
        ]);

        $budget = Budget::findOrFail($budgetId);

        DB::beginTransaction();
        try {
            // delete previous aggregated rows for this user+budget
            DB::table('budget_user_category_totals')
                ->where('budget_id', $budgetId)
                ->where('user_id', $userId)
                ->delete();

            DB::table('budget_user_totals')
                ->where('budget_id', $budgetId)
                ->where('user_id', $userId)
                ->delete();

            // Re-run a narrow process for this single user
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

    // -----------------------
    // Helpers
    // -----------------------

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
     * Obtiene el monto base en COP para un sale (no usado en agregación rápida,
     * pero lo dejo por compatibilidad si deseas volver a cálculo por venta).
     */
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
        // prefer model helper if exists
        if (method_exists($user, 'roleAtDate')) {
            $r = $user->roleAtDate($date);
            if ($r instanceof \App\Models\Role) return $r;
            if ($r && isset($r->role)) return $r->role;
        }

        // fallback to pivot
        if (method_exists($user, 'roles')) {
            $pivot = $user->roles()->with('role')->orderByDesc('start_date')->first();
            if ($pivot && $pivot->role) return $pivot->role;
        }

        return $user->role ?? null;
    }
}
