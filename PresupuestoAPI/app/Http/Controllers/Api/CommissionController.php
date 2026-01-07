<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\CommissionCalculator;

class CommissionController extends Controller
{
    public function recalcSale($saleId, CommissionCalculator $calculator)
    {
        $sale = Sale::with('user.userRoles')->findOrFail($saleId);

        $commission = $calculator->calculateForSale($sale);

        return response()->json([
            'commission' => $commission
        ]);
    }

    public function recalcUserMonth($userId, $month)
    {
        $sales = Sale::where('user_id', $userId)
            ->whereMonth('created_at', $month)
            ->get();

        $calculator = app(CommissionCalculator::class);

        $result = [];

        foreach ($sales as $sale) {
            $result[] = $calculator->calculateForSale($sale);
        }

        return response()->json($result);
    }
}
