<?php

use App\Http\Controllers\ImportSalesController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CommissionController;

Route::get('/v1/ping', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'API funcionando correctamente',
    ]);
});
Route::prefix('v1')->group(function () {
    Route::apiResource('budgets', \App\Http\Controllers\Api\BudgetController::class);
    Route::post('import-sales', [ImportSalesController::class, 'import']);
    Route::post('commissions/recalc-sale', [CommissionController::class,'recalcSale']);
    Route::post('commissions/recalc-batch', [CommissionController::class,'recalcBatch']);
    Route::post('commissions/recalc-user-month', [CommissionController::class,'recalcUserMonth']);
    Route::get('commissions/summary', [CommissionController::class,'userSummary']);
    Route::post('commissions/finalize', [CommissionController::class,'finalize']);

});
