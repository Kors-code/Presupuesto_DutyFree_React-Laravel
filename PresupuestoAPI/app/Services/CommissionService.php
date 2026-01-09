<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Sale;
use App\Models\Category;
use App\Models\CategoryCommission;
use App\Models\Commission;
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

    /**
     * Core processing for a given budget.
     */
    protected function processBudget(Budget $budget): array
    {
        Log::info('[COMMISSION] Processing budget', [
            'budget_id' => $budget->id,
            'range' => [$budget->start_date, $budget->end_date],
            'target_usd' => $budget->target_amount,
        ]);

        $totalTurns = $budget->total_turns ?? $this->TOTAL_TURNS;

        // total USD to determine provisional
        $totalUsd = Sale::whereBetween('sale_date', [$budget->start_date, $budget->end_date])
            ->sum(DB::raw('COALESCE(value_usd,0)'));

        $pct = $budget->target_amount > 0
            ? ($totalUsd / $budget->target_amount) * 100
            : 0;

        $isProvisional = $pct < $budget->min_pct_to_qualify;

        Log::info('[COMMISSION] Budget progress', [
            'total_sales_usd' => round($totalUsd, 2),
            'pct' => round($pct, 2),
            'is_provisional' => $isProvisional,
        ]);

        DB::beginTransaction();

        try {
            // 1) ventas agregadas por seller + grupo (USD)
            $caseFrag = $this->getSqlClassificationCase();

            $salesByUserGroupRows = Sale::selectRaw("
                    {$caseFrag} AS classification,
                    sales.seller_id,
                    SUM(COALESCE(sales.value_usd,0)) AS sales_usd
                ")
                ->leftJoin('products','sales.product_id','=','products.id')
                ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date])
                ->groupBy(DB::raw($caseFrag), 'sales.seller_id')
                ->get();

            $salesByUserGroup = []; // [user_id][group] => sales_usd
            $userIds = [];
            foreach ($salesByUserGroupRows as $r) {
                $grp = $this->normalizeClassification($r->classification);
                $uid = (int)$r->seller_id;
                $userIds[$uid] = true;
                $salesByUserGroup[$uid][$grp] = (float)$r->sales_usd;
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

            // 3) obtener assigned_turns de los usuarios que aparecen en ventas
            $assignedTurnsByUser = [];
            if (!empty($userIds)) {
                $users = User::whereIn('id', array_keys($userIds))->select('id','assigned_turns')->get();
                foreach ($users as $u) {
                    $assignedTurnsByUser[$u->id] = (int)($u->assigned_turns ?? 0);
                }
            }

            // 4) calcular pctUserByGroup [user][group] => ['pct','category_budget_for_user','sales_usd']
            $pctUserByGroup = [];
            foreach ($assignedTurnsByUser as $uid => $assigned) {
                $userBudgetUsd = $totalTurns > 0 ? round($budget->target_amount * ($assigned / $totalTurns), 2) : 0.0;

                foreach ($categoryGroupMap as $grp => $meta) {
                    $participation = $meta['participation_pct'] ?? 0;
                    $categoryBudgetForUser = $userBudgetUsd * ($participation / 100);

                    $salesUsd = $salesByUserGroup[$uid][$grp] ?? 0.0;
                    if ($categoryBudgetForUser > 0) {
                        $pctVal = round(($salesUsd / $categoryBudgetForUser) * 100, 2);
                    } else {
                        $pctVal = null;
                    }
                    $pctUserByGroup[$uid][$grp] = [
                        'pct' => $pctVal,
                        'category_budget_for_user' => $categoryBudgetForUser,
                        'sales_usd' => $salesUsd
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

                // store rule model for potential rule_id persistence
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

            // 6) process each sale and create/update commissions applying appliedPct from pctUserByGroup
            $sales = Sale::whereBetween('sale_date', [$budget->start_date, $budget->end_date])
                ->where(function($q){
                    $q->where('amount_cop','>',0)
                      ->orWhere('value_pesos','>',0)
                      ->orWhere('amount','>',0)
                      ->orWhere('value_usd','>',0);
                })
                ->with(['seller','product'])
                ->get();

            Log::info('[COMMISSION] Sales fetched for creation', [
                'count' => $sales->count()
            ]);

            $created = $updated = $skipped = 0;

            foreach ($sales as $sale) {
                if (!$sale->seller || !$sale->product) {
                    $skipped++; 
                    Log::warning('[SALE] Skipped missing relations', ['sale_id' => $sale->id]);
                    continue;
                }

                $baseCop = $this->getBaseCop($sale);
                if ($baseCop <= 0) {
                    $skipped++;
                    Log::warning('[SALE] Skipped baseCop <= 0', ['sale_id' => $sale->id]);
                    continue;
                }

                $classification = $this->normalizeClassification((string)$sale->product->classification);

                $beneficiaries = [$sale->seller];
                $cashierUser = $this->resolveCashierUser($sale);
                if ($cashierUser && $cashierUser->id !== $sale->seller->id) $beneficiaries[] = $cashierUser;

                foreach ($beneficiaries as $user) {
                    // resolve role for user at sale date
                    $role = $this->resolveRoleModelForUserAtDate($user, $sale->sale_date);
                    if (!$role) {
                        Log::warning('[BENEFICIARY] No role for user at date', ['user_id' => $user->id, 'sale_id' => $sale->id]);
                        continue;
                    }
                    $roleId = (int)$role->id;

                    // find rates for role+group
                    $rates = $ratesByGroupByRole[$roleId][$classification] ?? null;
                    if (!$rates) {
                        // try fallback: find a rule for any category in the group for this role
                        $possibleCatIds = $categoryGroupMap[$classification]['category_ids'] ?? [];
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
                        Log::warning('[RULE] No rates for role+group', [
                            'role_id' => $roleId,
                            'group' => $classification,
                            'sale_id' => $sale->id
                        ]);
                        $skipped++;
                        continue;
                    }

                    // pct for this user+group (precomputed)
                    $pctEntry = $pctUserByGroup[$user->id][$classification] ?? ['pct' => null];
                    $pctUser = $pctEntry['pct'];

                    // decide applied percentage
                    $appliedPct = 0.0;
                    if (is_null($pctUser) || $pctUser < (float)$budget->min_pct_to_qualify) {
                        $appliedPct = 0.0;
                    } else {
                        if ($pctUser >= 120) {
                            $appliedPct = $rates['pct120'] ?? $rates['pct100'] ?? $rates['base'] ?? 0.0;
                        } elseif ($pctUser >= 100) {
                            $appliedPct = $rates['pct100'] ?? $rates['base'] ?? 0.0;
                        } else {
                            $appliedPct = $rates['base'] ?? 0.0;
                        }
                    }

                    // commission in COP using appliedPct
                    $commissionCop = round($baseCop * ((float)$appliedPct / 100), 2);
                    if ($commissionCop <= 0) {
                        Log::warning('[COMMISSION] Computed zero commission', [
                            'sale_id' => $sale->id,
                            'user_id' => $user->id,
                            'applied_pct' => $appliedPct,
                            'base_cop' => $baseCop,
                        ]);
                        $skipped++;
                        continue;
                    }

                    // pick an appropriate rule_id to store (first matching category in group if exists)
                    $ruleIdToStore = null;
                    $possibleCatIds = $categoryGroupMap[$classification]['category_ids'] ?? [];
                    foreach ($possibleCatIds as $cid) {
                        if (isset($ruleByRoleCategory[$roleId][$cid])) {
                            $ruleIdToStore = $ruleByRoleCategory[$roleId][$cid]->id;
                            break;
                        }
                    }

                    $payload = [
                        'commission_amount' => $commissionCop,
                        'is_provisional' => $isProvisional,
                        'calculated_as' => $role->name,
                        'rule_id' => $ruleIdToStore,
                    ];

                    // persist applied pct if column exists
                    if (Schema::hasColumn('commissions', 'applied_commission_pct')) {
                        $payload['applied_commission_pct'] = $appliedPct;
                    } elseif (Schema::hasColumn('commissions', 'applied_pct')) {
                        $payload['applied_pct'] = $appliedPct;
                    } else {
                        // append for traceability without altering schema
                        $payload['calculated_as'] = ($payload['calculated_as'] ?? '') . " (applied_pct={$appliedPct})";
                    }

                    $commission = Commission::updateOrCreate(
                        [
                            'sale_id' => $sale->id,
                            'user_id' => $user->id,
                            'budget_id' => $budget->id,
                        ],
                        $payload
                    );

                    $commission->wasRecentlyCreated ? $created++ : $updated++;

                    Log::info('[COMMISSION] Saved', [
                        'commission_id' => $commission->id,
                        'sale_id' => $sale->id,
                        'user_id' => $user->id,
                        'commission_cop' => $commissionCop,
                        'applied_pct' => $appliedPct,
                    ]);
                } // end beneficiaries
            } // end sales loop

            DB::commit();

            Log::info('[COMMISSION] Finished processBudget', [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'pct' => round($pct,2),
                'is_provisional' => $isProvisional,
            ]);

            return [
                'status' => 'ok',
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'total_sales_usd' => round($totalUsd, 2),
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

    protected function getBaseCop(Sale $sale): float
    {
        if ($sale->amount_cop > 0) return (float) $sale->amount_cop;
        if ($sale->value_pesos > 0) return (float) $sale->value_pesos;
        if ($sale->amount > 0) return (float) $sale->amount;
        return 0;
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
