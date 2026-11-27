<?php

use App\Http\Controllers\SaleController;
use App\Models\CashRegister;
use App\Models\CashSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware('auth')->get('/sales/{id}/invoice', [SaleController::class, 'invoice'])
    ->name('sales.invoice');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['auth', 'verified', 'can:manage-cash-session'])->get('/cashier', function (Request $request) {
    $session = CashSession::query()
        ->with('register')
        ->where('opened_by', $request->user()->id)
        ->where('status', 'open')
        ->first();

    return inertia('cashier/index', [
        'registers' => CashRegister::query()->where('is_active', true)->orderBy('name')->get(),
        'cashSession' => $session,
        'summary' => $session?->summary(),
        'userRole' => $request->user()->role,
    ]);
})->name('cashier');

require __DIR__.'/settings.php';
