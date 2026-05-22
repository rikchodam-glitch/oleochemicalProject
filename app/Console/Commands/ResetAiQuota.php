<?php

namespace App\Console\Commands;

use App\Models\AiProvider;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ResetAiQuota extends Command
{
    protected $signature = 'ai:reset-quota
                            {--daily : Reset hanya quota harian}
                            {--monthly : Reset hanya quota bulanan}
                            {--force : Force reset tanpa cek tanggal}';
    protected $description = 'Reset kuota AI provider (harian & bulanan) secara otomatis';

    public function handle(): int
    {
        $now = now();
        $dailyReset = 0;
        $monthlyReset = 0;
        $providers = AiProvider::all();

        foreach ($providers as $provider) {
            $shouldResetDaily = $this->option('force') || $this->option('daily');
            $shouldResetMonthly = $this->option('force') || $this->option('monthly');

            // Cek apakah sudah waktunya reset harian (beda hari)
            if (!$shouldResetDaily && $provider->daily_reset_at) {
                $lastDaily = Carbon::parse($provider->daily_reset_at);
                if (!$lastDaily->isSameDay($now)) {
                    $shouldResetDaily = true;
                }
            } elseif (!$provider->daily_reset_at) {
                $shouldResetDaily = true;
            }

            // Cek apakah sudah waktunya reset bulanan (beda bulan)
            if (!$shouldResetMonthly && $provider->month_reset_at) {
                $lastMonthly = Carbon::parse($provider->month_reset_at);
                if (!$lastMonthly->isSameMonth($now)) {
                    $shouldResetMonthly = true;
                }
            } elseif (!$provider->month_reset_at) {
                $shouldResetMonthly = true;
            }

            $updates = [];

            if ($shouldResetDaily) {
                $updates['current_daily_tokens'] = 0;
                $updates['daily_reset_at'] = $now;
                $dailyReset++;
            }

            if ($shouldResetMonthly) {
                $updates['current_month_tokens'] = 0;
                $updates['month_reset_at'] = $now;
                $monthlyReset++;
            }

            if (!empty($updates)) {
                $provider->update($updates);
            }
        }

        $this->info("✅ Reset kuota selesai!");
        $this->line("   • Harian: {$dailyReset} provider di-reset");
        $this->line("   • Bulanan: {$monthlyReset} provider di-reset");

        return self::SUCCESS;
    }
}
