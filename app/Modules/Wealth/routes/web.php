<?php

use App\Modules\Wealth\Http\Controllers\WealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WealthController::class, 'index'])->name('index');
