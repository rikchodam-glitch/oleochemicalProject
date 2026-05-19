<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MaintenanceReportController;

// Route Halaman Dashboard Utama (Menu)
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Route Asset Manager
Route::get('/assets', [AssetController::class, 'index'])->name('assets.index');
Route::post('/assets', [AssetController::class, 'store'])->name('assets.store');
Route::post('/assets/{id}', [AssetController::class, 'update'])->name('assets.update');
Route::delete('/assets/{id}', [AssetController::class, 'destroy'])->name('assets.destroy');
Route::get('/assets/{id}/edit', [AssetController::class, 'edit'])->name('assets.edit');
Route::get('/export-assets', [AssetController::class, 'export'])->name('assets.export');
Route::get('/export-template', [AssetController::class, 'exportTemplate'])->name('assets.template');
Route::post('/import-assets', [AssetController::class, 'import'])->name('assets.import');

// Route API untuk Dropdown
Route::get('/api/departments/{companyId}', [AssetController::class, 'getDepartments']);
Route::get('/api/areas/{deptId}', [AssetController::class, 'getAreas']);
Route::get('/api/sub-areas/{areaId}', [AssetController::class, 'getSubAreas']);
Route::get('/api/maintenance-history/{asset_id}', [AssetController::class, 'getHistory']);
Route::get('/api/employees', [EmployeeController::class, 'apiList']);

// Route Halaman Detail Maintenance
Route::get('/maintenance', [AssetController::class, 'maintenanceDetail'])->name('maintenance.detail');

// Route Manajemen Laporan (Report Manager)
Route::get('/reports', [MaintenanceReportController::class, 'index'])->name('reports.index');

// Route CRUD Maintenance Report
Route::post('/maintenance-reports', [MaintenanceReportController::class, 'store'])->name('maintenance-reports.store');
Route::post('/maintenance-reports/{id}', [MaintenanceReportController::class, 'update'])->name('maintenance-reports.update');
Route::delete('/maintenance-reports/{id}', [MaintenanceReportController::class, 'destroy'])->name('maintenance-reports.destroy');
Route::get('/maintenance-reports/{id}/edit', [MaintenanceReportController::class, 'edit'])->name('maintenance-reports.edit');

// Route Manajemen Karyawan
Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
Route::post('/employees/{id}/update', [EmployeeController::class, 'update'])->name('employees.update');
Route::post('/employees/{id}/connect', [EmployeeController::class, 'connect'])->name('employees.connect');
Route::post('/employees/{id}/disconnect', [EmployeeController::class, 'disconnect'])->name('employees.disconnect');
Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');

// ========== TELEGRAM BOT ROUTES ==========

// Webhook endpoint — exclude from CSRF via bootstrap/app.php
Route::post('/api/telegram/webhook', [App\Http\Controllers\TelegramBotController::class, 'webhook']);

// Panel Kontrol Bot Telegram
Route::get('/telegram-control', [App\Http\Controllers\TelegramControlPanelController::class, 'index'])
    ->name('telegram.control');
Route::post('/telegram-control/settings', [App\Http\Controllers\TelegramControlPanelController::class, 'updateSettings'])
    ->name('telegram.settings');
Route::post('/telegram-control/set-webhook', [App\Http\Controllers\TelegramControlPanelController::class, 'setWebhook'])
    ->name('telegram.set-webhook');
Route::post('/telegram-control/delete-webhook', [App\Http\Controllers\TelegramControlPanelController::class, 'deleteWebhook'])
    ->name('telegram.delete-webhook');
Route::post('/telegram-control/approve-registration/{logId}', [App\Http\Controllers\TelegramControlPanelController::class, 'approveRegistration'])
    ->name('telegram.approve');
Route::post('/telegram-control/reject-registration/{logId}', [App\Http\Controllers\TelegramControlPanelController::class, 'rejectRegistration'])
    ->name('telegram.reject');
Route::post('/telegram-control/block', [App\Http\Controllers\TelegramControlPanelController::class, 'blockUser'])
    ->name('telegram.block');
Route::post('/telegram-control/unblock/{id}', [App\Http\Controllers\TelegramControlPanelController::class, 'unblockUser'])
    ->name('telegram.unblock');
Route::post('/telegram-control/reprocess/{logId}', [App\Http\Controllers\TelegramControlPanelController::class, 'reprocessLog'])
    ->name('telegram.reprocess');
Route::delete('/telegram-control/log/{logId}', [App\Http\Controllers\TelegramControlPanelController::class, 'deleteLog'])
    ->name('telegram.delete-log');
Route::post('/telegram-control/clean-logs', [App\Http\Controllers\TelegramControlPanelController::class, 'cleanLogs'])
    ->name('telegram.clean-logs');


