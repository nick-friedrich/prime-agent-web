<?php

use App\Http\Controllers\AgentChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectDirectoryController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/project-directories/search', [ProjectDirectoryController::class, 'search'])->name('project-directories.search');
Route::get('/project-directories/browse', [ProjectDirectoryController::class, 'browse'])->name('project-directories.browse');
Route::post('/projects', [DashboardController::class, 'storeProject'])->name('projects.store');
Route::get('/agents/new', [AgentChatController::class, 'create'])->name('agents.create');
Route::post('/agents', [AgentChatController::class, 'store'])->name('agents.store');
Route::get('/agents/{sessionId}', [AgentChatController::class, 'show'])->name('agents.show');
Route::get('/agents/{sessionId}/transcript', [AgentChatController::class, 'transcript'])->name('agents.transcript');
Route::post('/agents/{sessionId}/messages', [AgentChatController::class, 'storeMessage'])->name('agents.messages.store');
Route::get('/agents/{sessionId}/attachments/{attachmentId}', [AgentChatController::class, 'attachment'])->name('agents.attachments.show');
Route::delete('/agents/{sessionId}', [AgentChatController::class, 'destroy'])->name('agents.destroy');
