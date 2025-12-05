<?php
namespace App\Services;

use App\Models\Sale;
use App\Models\CategoryCommission;
use App\Models\Commission;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CommissionCalculator
{
    /**
     * Calcula la comisión para una venta y la guarda (provisional).
     * Si ya existe comisión para la venta+user+rule, la reemplaza (útil para recalc).
     */
    public function calculateForSale(Sale $sale, $forUserId = null): ?Commission
    {
        // Determinar target users: puede ser seller y/o cashier, si forUserId se fuerza
        $targets = [];
        if ($forUserId) $targets = [$forUserId];
        else {
            if ($sale->seller_id) $targets[] = $sale->seller_id;
            if ($sale->cashier_id) $targets[] = $sale->cashier_id;
        }

        $created = null;

        DB::transaction(function() use ($sale, $targets, &$created) {
            foreach ($targets as $userId) {
                // Determinar role del usuario en la fecha de venta usando user_roles relationship
                $user = \App\Models\User::find($userId);
                if (!$user) continue;

                $roleRecord = $user->roleAtDate($sale->sale_date);
                $roleId = $roleRecord?->role?->id ?? null;
                $roleName = $roleRecord?->role?->name ?? null;

                // determinar category
                $catName = $sale->product?->classification ?? null;
                $category = null;
                if ($catName) {
                    $category = \App\Models\Category::where('name', $catName)->first();
                }

                if (!$category || !$roleId) {
                    // no hay regla posible
                    continue;
                }

                $rule = CategoryCommission::where('category_id', $category->id)
                    ->where('role_id', $roleId)
                    ->first();

                if (!$rule) continue;

                $percentage = $rule->commission_percentage;
                // Opcional: si hay thresholds y existe presupuesto / cumplimiento, aplicar bonus
                if ($rule->min_threshold_pct !== null || $rule->bonus_percentage !== null) {
                    // obtener % cumplimiento del vendedor para ese mes (si aplica)
                    // Se asume que existe un Budget que se asigna por user por mes; si no, omitimos
                    $budget = \App\Models\Budget::where('month', Carbon::parse($sale->sale_date)->format('Y-m'))->first();
                    // NOTE: la lógica real de "cumplimiento" depende de cómo asignes presupuestos por usuario
                    // Aquí hacemos un placeholder: si budget existe, se puede calcular % cumplimiento total usuario
                    if ($budget) {
                        $totalUserSales = Sale::where('seller_id', $userId)
                            ->whereBetween('sale_date', [
                                Carbon::parse($sale->sale_date)->startOfMonth()->toDateString(),
                                Carbon::parse($sale->sale_date)->endOfMonth()->toDateString()
                            ])->sum('amount');
                        $pct = $budget->amount > 0 ? ($totalUserSales / $budget->amount) * 100.0 : 0.0;
                        if ($rule->min_threshold_pct !== null && $rule->max_threshold_pct !== null && $rule->bonus_percentage !== null) {
                            if ($pct >= $rule->min_threshold_pct && $pct <= $rule->max_threshold_pct) {
                                $percentage += $rule->bonus_percentage;
                            }
                        }
                    }
                }

                $commissionAmount = round(($sale->amount * ($percentage / 100.0)), 2);

                // Eliminar comisiones previas provisionales para misma sale+user+rule si existen
                Commission::where('sale_id', $sale->id)
                    ->where('user_id', $userId)
                    ->where('rule_id', $rule->id)
                    ->delete();

                $created = Commission::create([
                    'sale_id' => $sale->id,
                    'user_id' => $userId,
                    'commission_amount' => $commissionAmount,
                    'calculated_as' => $roleName ?? null,
                    'rule_id' => $rule->id,
                    'is_provisional' => true,
                ]);
            }
        });

        return $created;
    }

    /**
     * Recalcula todas las comisiones de ventas en un import_batch (útil tras corregir reglas)
     */
    public function recalcBatch(int $batchId): array
    {
        $sales = Sale::where('import_batch_id', $batchId)->get();
        $recalc = ['processed' => 0, 'errors' => 0];
        foreach ($sales as $s) {
            try {
                $this->calculateForSale($s);
                $recalc['processed']++;
            } catch (\Throwable $e) {
                Log::error("Recalc sale {$s->id} error: ".$e->getMessage());
                $recalc['errors']++;
            }
        }
        return $recalc;
    }

    /**
     * Recalcula ventas por usuario en un mes
     */
    public function recalcUserMonth(int $userId, string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth()->toDateString();
        $end = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();
        $sales = Sale::where(function($q) use ($userId) {
                    $q->where('seller_id', $userId)->orWhere('cashier_id', $userId);
                })
                ->whereBetween('sale_date', [$start, $end])
                ->get();
        $recalc = ['processed' => 0, 'errors' => 0];
        foreach ($sales as $s) {
            try {
                $this->calculateForSale($s);
                $recalc['processed']++;
            } catch (\Throwable $e) {
                Log::error("Recalc user {$userId} sale {$s->id} error: ".$e->getMessage());
                $recalc['errors']++;
            }
        }
        return $recalc;
    }
}
