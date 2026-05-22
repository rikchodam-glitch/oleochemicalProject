<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ========== SCHEDULED TASKS ==========

// Reset kuota harian AI — setiap jam 00:00
Schedule::command('ai:reset-quota --daily')
    ->dailyAt('00:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/ai-quota-reset.log'));

// Reset kuota bulanan AI — setiap tanggal 1 jam 00:05
Schedule::command('ai:reset-quota --monthly')
    ->cron('5 0 1 * *')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/ai-quota-reset.log'));

// Cek dan reset on-the-fly setiap jam (sebagai safety jika cron daily terlewat)
Schedule::command('ai:reset-quota')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/ai-quota-reset.log'));
