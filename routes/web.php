<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectDirectoryController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/project-directories/search', [ProjectDirectoryController::class, 'search'])->name('project-directories.search');
Route::get('/project-directories/browse', [ProjectDirectoryController::class, 'browse'])->name('project-directories.browse');
Route::post('/projects', [DashboardController::class, 'storeProject'])->name('projects.store');
Route::post('/agents', [DashboardController::class, 'storeAgent'])->name('agents.store');
