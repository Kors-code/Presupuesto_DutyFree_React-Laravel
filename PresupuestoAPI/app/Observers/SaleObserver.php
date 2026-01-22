<?php

namespace App\Observers;

use App\Models\Sale;
use App\Services\CommissionService;

class SaleObserver
{
    protected CommissionService $commissionService;

    public function __construct(CommissionService $commissionService)
    {
        $this->commissionService = $commissionService;
    }

    /** CUANDO SE CREA UNA VENTA */
    public function created(Sale $sale): void
    {
        $this->commissionService->onSaleCreated($sale);
    }

    /** CUANDO SE ACTUALIZA UNA VENTA */
    public function updated(Sale $sale): void
{
    $budgetId = $sale->budget_id;
    if (!$budgetId) return;

    $this->commissionService
        ->recalcForUserAndBudget($sale->seller_id, $budgetId);
}


    /** CUANDO SE ELIMINA UNA VENTA */
    public function deleted(Sale $sale): void
    {
        $this->commissionService->onSaleDeleted($sale);
    }
}
