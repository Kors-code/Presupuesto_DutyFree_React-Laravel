<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Sale;
use App\Models\Category;
use App\Models\CategoryCommission;
use App\Models\Commission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommissionService
{
    /**
     * Genera comisiones para el presupuesto activo.
     *
     * - Siempre genera comisión para el seller.
     * - Si hay venta cruzada (cashier distinto del seller) e
     *   existe un usuario cashier coincidente, también genera
     *   comisión para ese cashier (si aplica regla).
     *
     * Devuelve diagnóstico para depuración.
     */
    public function generateForActiveBudget(): array
    {
        $today = now()->toDateString();

        $budget = Budget::where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->first();

        if (!$budget) {
            return ['status' => 'no_budget'];
        }

        // total en USD dentro del rango (para pct)
        $totalUsd = Sale::whereBetween('sale_date', [
            $budget->start_date,
            $budget->end_date
        ])->sum(DB::raw('COALESCE(value_usd,0)'));

        $pct = $budget->target_amount > 0
            ? ($totalUsd / $budget->target_amount) * 100
            : 0;

        $isProvisional = $pct < $budget->min_pct_to_qualify;

        DB::beginTransaction();

        try {
            $sales = Sale::whereBetween('sale_date', [
                    $budget->start_date,
                    $budget->end_date
                ])
                ->where(function ($q) {
                    $q->whereNotNull('amount_cop')
                      ->orWhereNotNull('value_pesos')
                      ->orWhere('amount', '>', 0);
                })
                ->with(['seller.roles.role', 'product']) // intenta eager load role si existe en tus relaciones
                ->get();

            if ($sales->isEmpty()) {
                DB::commit();
                return [
                    'status' => 'no_sales_in_budget_range',
                    'budget_start' => $budget->start_date,
                    'budget_end' => $budget->end_date,
                    'total_sales_usd' => round($totalUsd, 2)
                ];
            }

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $diagnostics = [];

            foreach ($sales as $sale) {
                $saleDiag = [
                    'sale_id' => $sale->id,
                    'sale_date' => $sale->sale_date,
                    'product_id' => $sale->product_id,
                    'seller_id' => $sale->seller_id,
                    'cashier_text' => $sale->cashier,
                    'processed_for' => [],
                    'skipped_reasons' => []
                ];

                // sanity: seller y product deben existir
                if (!$sale->seller) {
                    $saleDiag['skipped_reasons'][] = 'no_seller';
                    $diagnostics[] = $saleDiag;
                    $skipped++;
                    continue;
                }

                if (!$sale->product) {
                    $saleDiag['skipped_reasons'][] = 'no_product';
                    $diagnostics[] = $saleDiag;
                    $skipped++;
                    continue;
                }

                // calculo de base (COP)
                $baseCop = $this->getBaseCop($sale);
                if (!is_numeric($baseCop) || $baseCop <= 0) {
                    $saleDiag['skipped_reasons'][] = 'invalid_baseCop';
                    $saleDiag['baseCop'] = $baseCop;
                    $diagnostics[] = $saleDiag;
                    $skipped++;
                    continue;
                }

                // categoria del producto
                $categoryCode = $sale->product->classification;
                if (!$categoryCode) {
                    $saleDiag['skipped_reasons'][] = 'no_product_classification';
                    $diagnostics[] = $saleDiag;
                    $skipped++;
                    continue;
                }

                $category = Category::where('classification_code', $categoryCode)->first();
                if (!$category) {
                    $saleDiag['skipped_reasons'][] = 'category_not_found';
                    $saleDiag['classification_code'] = $categoryCode;
                    $diagnostics[] = $saleDiag;
                    $skipped++;
                    continue;
                }

                // --- Beneficiarios: seller siempre ---
                $beneficiaries = [];

                $beneficiaries[] = $sale->seller;

                // --- Si es cross sale: intentar añadir cashier user ---
                $cashierUser = $this->resolveCashierUser($sale);
                if ($cashierUser && $cashierUser->id !== $sale->seller->id) {
                    $beneficiaries[] = $cashierUser;
                    $saleDiag['matched_cashier_id'] = $cashierUser->id;
                }

                // por cada beneficiario intentar calcular regla y guardar comisión
                foreach ($beneficiaries as $beneficiary) {
                    // resolver Role model correcto para la fecha de la venta
                    $roleModel = $this->resolveRoleModelForUserAtDate($beneficiary, $sale->sale_date);

                    if (!$roleModel) {
                        $saleDiag['skipped_reasons'][] = "no_role_for_user_{$beneficiary->id}";
                        continue;
                    }

                    // buscar regla por category + role
                    $rule = CategoryCommission::where('category_id', $category->id)
                        ->where('role_id', $roleModel->id)
                        ->first();

                    if (!$rule || floatval($rule->commission_percentage) <= 0) {
                        $saleDiag['skipped_reasons'][] = "no_rule_for_category_{$category->id}_role_{$roleModel->id}";
                        continue;
                    }

                    // calcular comisión (la columna commission_percentage en tu BD parece estar en formato "porcentaje"
                    // p.ej. 0.50 representa 0.50%; por eso se divide entre 100)
                    $commissionCop = round($baseCop * (floatval($rule->commission_percentage) / 100), 2);

                    if ($commissionCop <= 0) {
                        $saleDiag['skipped_reasons'][] = 'computed_commission_zero';
                        continue;
                    }

                    // crear o actualizar comision
                    $commission = Commission::updateOrCreate(
                        [
                            'sale_id' => $sale->id,
                            'user_id' => $beneficiary->id,
                        ],
                        [
                            'commission_amount' => $commissionCop,
                            'is_provisional' => $isProvisional,
                            'calculated_as' => $roleModel->name,
                            'rule_id' => $rule->id,
                        ]
                    );

                    // contabilizar created/updated
                    // el modelo Eloquent marca ->wasRecentlyCreated cuando fue creado en esta instancia.
                    if (isset($commission->wasRecentlyCreated) && $commission->wasRecentlyCreated) {
                        $created++;
                    } else {
                        $updated++;
                    }

                    $saleDiag['processed_for'][] = [
                        'user_id' => $beneficiary->id,
                        'commission' => $commissionCop,
                        'rule_id' => $rule->id,
                        'role_id' => $roleModel->id,
                    ];
                } // end beneficiaries

                if (empty($saleDiag['processed_for'])) {
                    $skipped++;
                }

                $diagnostics[] = $saleDiag;
            } // end foreach sales

            DB::commit();

            return [
                'status' => 'ok',
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'total_sales_usd' => round($totalUsd, 2),
                'target_usd' => $budget->target_amount,
                'pct' => round($pct, 2),
                'min_pct' => $budget->min_pct_to_qualify,
                'missing_pct' => max(0, round($budget->min_pct_to_qualify - $pct, 2)),
                'missing_usd' => max(0, round(
                    ($budget->target_amount * $budget->min_pct_to_qualify / 100) - $totalUsd,
                    2
                )),
                'is_provisional' => $isProvisional,
                'status_label' => $isProvisional ? 'PROVISIONAL' : 'LIBERADO',
                'diagnostics' => array_slice($diagnostics, 0, 200) // los primeros 200 para evitar respuestas enormes
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            // opcional: loguear $e->getMessage()
            throw $e;
        }
    }

    /**
     * Resuelve el valor base en COP para calcular la comisión.
     */
    protected function getBaseCop(Sale $sale)
    {
        // preferencia: amount_cop, value_pesos, amount
        if (!is_null($sale->amount_cop) && floatval($sale->amount_cop) != 0.0) {
            return floatval($sale->amount_cop);
        }

        if (!is_null($sale->value_pesos) && floatval($sale->value_pesos) != 0.0) {
            return floatval($sale->value_pesos);
        }

        if (!is_null($sale->amount) && floatval($sale->amount) != 0.0) {
            return floatval($sale->amount);
        }

        return 0;
    }

    /**
     * Intenta resolver el usuario que corresponde al texto cashier.
     * - Si existe columna cashier_id en la tabla sales, se usa primero.
     * - Luego intenta matching exacto LOWER(TRIM(name))
     * - Por último intenta un LIKE laxo.
     */
    protected function resolveCashierUser(Sale $sale)
    {
        // Si la columna cashier_id existe y tiene valor, usarla
        if (isset($sale->cashier_id) && $sale->cashier_id) {
            return User::find($sale->cashier_id);
        }

        $cashierText = trim((string)$sale->cashier);
        if ($cashierText === '') {
            return null;
        }

        // búsqueda exacta case-insensitive
        $normalized = mb_strtolower(trim($cashierText));
        $user = User::whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->first();
        if ($user) return $user;

        // búsqueda laxa: LIKE con espacios reemplazados por %
        $like = '%' . str_replace(' ', '%', $cashierText) . '%';
        $user = User::where('name', 'like', $like)->first();
        if ($user) return $user;

        return null;
    }

    /**
     * Dado un User y una fecha, devolver el Role model (no el pivot user_role).
     * - Si existe método roleAtDate que devuelve pivot con relation role, lo usa.
     * - Fallback: toma el último user_role con relation role.
     */
    protected function resolveRoleModelForUserAtDate(User $user, $date)
    {
        // Si existe método roleAtDate (tu código lo mencionaba), llamarlo.
        if (method_exists($user, 'roleAtDate')) {
            $ur = $user->roleAtDate($date);
            if ($ur && isset($ur->role) && $ur->role) {
                return $ur->role;
            }
            // si roleAtDate devolviera directamente un Role, manejar eso
            if ($ur && $ur instanceof \App\Models\Role) {
                return $ur;
            }
        }

        // fallback: tomar el último user_role y traer su role
        // asumimos que la relación 'roles' en user apunta al pivot user_roles; cargamos role dentro
        try {
            $lastUserRole = $user->roles()->with('role')->latest('start_date')->first();
            if ($lastUserRole && isset($lastUserRole->role) && $lastUserRole->role) {
                return $lastUserRole->role;
            }
        } catch (\Throwable $e) {
            // en caso de que roles() no sea pivot o no esté definida, intentar que user->role exista
            if (isset($user->role) && $user->role) {
                return $user->role;
            }
        }

        return null;
    }
}
