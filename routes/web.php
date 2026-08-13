<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/projects', [DashboardController::class, 'storeProject'])->name('projects.store');
Route::post('/agents', [DashboardController::class, 'storeAgent'])->name('agents.store');
