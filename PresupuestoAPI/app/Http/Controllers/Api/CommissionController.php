<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\CommissionService;

class CommissionController extends Controller
{
    protected CommissionService $svc;
    public function __construct(CommissionService $svc)
    {
        $this->svc = $svc;
    }

    // POST /api/commissions/generate?month=YYYY-MM or ?budget_id=ID
    public function generate(Request $request, CommissionService $service)
    {
        $request->validate([
            'budget_id' => 'required|exists:budgets,id'
        ]);

        return response()->json(
            $service->generateForBudget($request->budget_id)
        );
    }

}
