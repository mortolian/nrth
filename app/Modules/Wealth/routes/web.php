<?php

use App\Modules\Wealth\Http\Controllers\WealthAllowanceController;
use App\Modules\Wealth\Http\Controllers\WealthAssetController;
use App\Modules\Wealth\Http\Controllers\WealthDashboardController;
use App\Modules\Wealth\Http\Controllers\WealthPortfolioController;
use App\Modules\Wealth\Http\Controllers\WealthTransactionController;
use App\Modules\Wealth\Http\Controllers\WealthValuationController;
use Illuminate\Support\Facades\Route;

Route::get('/', WealthDashboardController::class)->name('index');

Route::post('/portfolios', [WealthPortfolioController::class, 'store'])->name('portfolios.store');
Route::put('/portfolios/{portfolio}', [WealthPortfolioController::class, 'update'])->withTrashed()->name('portfolios.update');
Route::post('/portfolios/{portfolio}/select', [WealthPortfolioController::class, 'select'])->withTrashed()->name('portfolios.select');
Route::delete('/portfolios/{portfolio}', [WealthPortfolioController::class, 'destroy'])->name('portfolios.destroy');
Route::post('/portfolios/{portfolio}/restore', [WealthPortfolioController::class, 'restore'])->withTrashed()->name('portfolios.restore');
Route::delete('/portfolios/{portfolio}/force', [WealthPortfolioController::class, 'forceDestroy'])->withTrashed()->name('portfolios.force-destroy');

Route::get('/assets/create', [WealthAssetController::class, 'create'])->name('assets.create');
Route::post('/assets', [WealthAssetController::class, 'store'])->name('assets.store');
Route::get('/assets/{asset}', [WealthAssetController::class, 'show'])->withTrashed()->name('assets.show');
Route::get('/assets/{asset}/edit', [WealthAssetController::class, 'edit'])->withTrashed()->name('assets.edit');
Route::put('/assets/{asset}', [WealthAssetController::class, 'update'])->withTrashed()->name('assets.update');
Route::delete('/assets/{asset}', [WealthAssetController::class, 'destroy'])->name('assets.destroy');
Route::post('/assets/{asset}/restore', [WealthAssetController::class, 'restore'])->withTrashed()->name('assets.restore');
Route::delete('/assets/{asset}/force', [WealthAssetController::class, 'forceDestroy'])->withTrashed()->name('assets.force-destroy');

Route::post('/assets/{asset}/valuations', [WealthValuationController::class, 'store'])->name('assets.valuations.store');
Route::put('/assets/{asset}/valuations/{valuation}', [WealthValuationController::class, 'update'])->name('assets.valuations.update');
Route::delete('/assets/{asset}/valuations/{valuation}', [WealthValuationController::class, 'destroy'])->name('assets.valuations.destroy');

Route::post('/assets/{asset}/transactions', [WealthTransactionController::class, 'store'])->name('assets.transactions.store');
Route::put('/assets/{asset}/transactions/{transaction}', [WealthTransactionController::class, 'update'])->name('assets.transactions.update');
Route::delete('/assets/{asset}/transactions/{transaction}', [WealthTransactionController::class, 'destroy'])->name('assets.transactions.destroy');

Route::get('/allowances', [WealthAllowanceController::class, 'index'])->name('allowances.index');
Route::post('/allowances', [WealthAllowanceController::class, 'store'])->name('allowances.store');
Route::delete('/allowances/{allowance}', [WealthAllowanceController::class, 'destroy'])->name('allowances.destroy');
