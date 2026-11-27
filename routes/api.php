<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BestSellingProductsController;
use App\Http\Controllers\CashMovementController;
use App\Http\Controllers\CashSessionController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerPurchaseHistoryController;
use App\Http\Controllers\EmployeeSalesReportController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinancialReportController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\StockAlertController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockEntryController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [UserController::class, 'sendPasswordResetLink']);
Route::post('/reset-password', [UserController::class, 'resetPassword']);
Route::post('/users', [UserController::class, 'store']);

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/lookup/{identifier}', [ProductController::class, 'lookup'])->middleware('auth:sanctum');
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{id}', [CategoryController::class, 'show']);
Route::get('/teste', function () {
    return response()->json([
        'success' => true,
        'message' => 'A minha API está a funcionar!',
    ]);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::patch('/user', [UserController::class, 'update']);
    Route::patch('/me', [AuthController::class, 'update']);
    Route::delete('/me', [AuthController::class, 'destroy']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/sessions', [AuthController::class, 'sessions']);
    Route::delete('/sessions/{session}', [AuthController::class, 'destroySession']);

    Route::get('/sales', [SaleController::class, 'index']);
    Route::post('/sales', [SaleController::class, 'store']);
    Route::post('/sales/{sale}/returns', [SaleReturnController::class, 'store']);
    Route::get('/sales/{id}/invoice', [SaleController::class, 'invoice']);
    Route::get('/sales/{id}', [SaleController::class, 'show']);
    Route::get('/me/purchases', [CustomerPurchaseHistoryController::class, 'mine']);
});

Route::middleware(['auth:sanctum', 'can:manage-store'])->group(function () {
    Route::post('/cash-registers', [CashSessionController::class, 'createRegister']);
    Route::patch('/cash-registers/{cashRegister}', [CashSessionController::class, 'updateRegister']);

    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

    Route::get('/stock/alerts', [StockAlertController::class, 'index']);
    Route::get('/stock/entries', [StockEntryController::class, 'index']);
    Route::get('/stock/movements', [StockController::class, 'movements']);
    Route::patch('/stock/{product}', [StockController::class, 'update']);
    Route::get('/reports/sales', [SaleController::class, 'report']);
    Route::get('/reports/sales/by-employee', [EmployeeSalesReportController::class, 'index']);
    Route::get('/reports/financial', [FinancialReportController::class, 'index']);
    Route::get('/reports/best-selling-products', [BestSellingProductsController::class, 'index']);

    Route::post('/sales/{id}/cancel', [SaleController::class, 'cancel']);
    Route::patch('/sales/{id}/refund', [SaleController::class, 'refund']);
});

Route::middleware(['auth:sanctum', 'can:manage-cash'])->group(function () {
    Route::get('/sellers', [UserController::class, 'sellers']);
    Route::get('/cash-movements', [CashMovementController::class, 'index']);
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'can:manage-cash-session'])->group(function () {
    Route::get('/cash-registers', [CashSessionController::class, 'registers']);
    Route::get('/cash-sessions/current', [CashSessionController::class, 'current']);
    Route::get('/cash-sessions', [CashSessionController::class, 'index']);
    Route::post('/cash-sessions', [CashSessionController::class, 'open']);
    Route::get('/cash-sessions/{cashSession}', [CashSessionController::class, 'show']);
    Route::post('/cash-sessions/{cashSession}/close', [CashSessionController::class, 'close']);
    Route::post('/cash-sessions/{cashSession}/movements', [CashSessionController::class, 'storeMovement']);
});

Route::middleware(['auth:sanctum', 'can:manage-staff'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::patch('/users/{user}/role', [UserController::class, 'updateRole']);
    Route::get('/users/{user}/purchases', [CustomerPurchaseHistoryController::class, 'forCustomer']);
});

Route::middleware(['auth:sanctum', 'can:manage-cash-session'])->get('/clients', [UserController::class, 'clients']);

Route::middleware(['auth:sanctum', 'can:admin'])->group(function () {
    Route::post('/staff', [UserController::class, 'createStaff']);
    Route::delete('/staff/{user}', [UserController::class, 'deleteStaff']);
});

Route::middleware(['auth:sanctum', 'can:manage-store'])->group(function () {
    Route::get('/stock', [StockController::class, 'index']);
    Route::get('/stock/{product}', [StockController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'can:admin'])->group(function () {
    Route::get('/audit-logs', [UserController::class, 'auditLogs']);
});

require __DIR__.'/purchases.php';
