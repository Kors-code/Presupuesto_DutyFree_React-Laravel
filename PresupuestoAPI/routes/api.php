<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImportSalesController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\CommissionReportController;
use App\Http\Controllers\Api\CategoryCommissionController;
use App\Http\Controllers\Api\ImportBatchController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\SalesByUserController;
use App\Http\Controllers\Api\BudgetProgressController;
use App\Http\Controllers\Api\CommissionActionController;






Route::get('/v1/ping', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'API funcionando correctamente',
    ]);
});

Route::prefix('v1')->group(function () {
    
    // USERS & ROLES
    Route::get('users', [UserController::class, 'index']);
    Route::post('users/{id}/assign-role', [UserController::class, 'assignRole']);
    Route::get('roles', [RoleController::class, 'index']);
    
    // IMPORTS (EXCEL)
    Route::post('import-sales', [ImportSalesController::class, 'import']);
    Route::get('imports', [ImportBatchController::class, 'index']);
    Route::get('imports/{id}', [ImportBatchController::class, 'show']);
    Route::delete('imports/{id}', [ImportBatchController::class, 'destroy']);
    
    // SALES
    Route::get('sales/users', [SalesByUserController::class, 'getUsersWithSales']);
    Route::get('sales/by-user', [SalesByUserController::class, 'getSalesByUser']);
    
    // COMMISSIONS – LOGIC
    Route::post('commissions/recalc-sale/{id}', [CommissionController::class,'recalcSale']);
    Route::post('commissions/recalc-user/{userId}/{month}', [CommissionController::class,'recalcUserMonth']);
    Route::get('commissions/summary', [CommissionController::class,'userSummary']);
    Route::post('commissions/finalize', [CommissionController::class,'finalize']);
    
    // COMMISSIONS – REPORTS (👈 TU CONTROLADOR)
    
    Route::get('/commissions/by-seller', [CommissionReportController::class, 'bySeller']);
    Route::get('/commissions/by-seller/{userId}', [CommissionReportController::class, 'bySellerDetail']);
    Route::put('commissions/{userId}/assign-turns', [CommissionReportController::class, 'assignTurns']);
    
    
    // COMMISSION CONFIG
    Route::get('commissions/categories', [CategoryCommissionController::class, 'index']);
    Route::post('commissions/categories', [CategoryCommissionController::class, 'upsert']);
    Route::delete('commissions/categories/{id}', [CategoryCommissionController::class, 'destroy']);
    Route::post('commissions/categories/bulk', [CategoryCommissionController::class, 'bulkUpdate']);
    
    Route::post('/commissions/generate', [CommissionController::class, 'generate']);
    // Budget 
    
    Route::get('/budgets', [BudgetController::class, 'index']);
    Route::post('/budgets', [BudgetController::class, 'store']);
    Route::get('/budgets/active', [BudgetController::class, 'active']);
    Route::get('/budgets/progress/daily', [BudgetProgressController::class, 'daily']);





});
