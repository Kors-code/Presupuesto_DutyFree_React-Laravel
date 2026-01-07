<?php
namespace App\Services;

use App\Models\Sale;
use App\Models\Commission;
use App\Models\CategoryCommission;
use Carbon\Carbon;

class CommissionCalculator
{
    public function calculateForSale(Sale $sale)
    {
        // 1. Rol activo del usuario
        $userRole = $sale->user
            ->userRoles()
            ->whereNull('end_date')
            ->first();

        if (!$userRole) {
            return null; // usuario sin rol activo
        }

        // 2. Regla de comisión por rol + categoría
        $rule = CategoryCommission::where('role_id', $userRole->role_id)
            ->where('category_id', $sale->category_id)
            ->first();

        if (!$rule) {
            return null; // no hay regla
        }

        // 3. Cálculo
        $amount = $sale->total * ($rule->percentage / 100);

        // 4. Guardar comisión
        return Commission::updateOrCreate(
            ['sale_id' => $sale->id],
            [
                'user_id' => $sale->user_id,
                'role_id' => $userRole->role_id,
                'category_id' => $sale->category_id,
                'percentage' => $rule->percentage,
                'amount' => round($amount, 2),
                'calculated_at' => Carbon::now(),
            ]
        );
    }
}
