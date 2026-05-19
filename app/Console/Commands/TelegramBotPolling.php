<?php

namespace App\Console\Commands;

use App\Http\Controllers\TelegramBotController;
use App\Models\TelegramSetting;
use App\Services\Telegram\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TelegramBotPolling extends Command
{
    protected $signature = 'telegram:polling';
    protected $description = 'Jalankan long-polling Telegram Bot (alternatif webhook untuk local dev)';

    public function handle()
    {
        $telegram = new TelegramService();
        $controller = new TelegramBotController();

        $lastUpdateId = 0;

        $this->info('🤖 Telegram Bot Polling dimulai...');
        $this->info('Tekan Ctrl+C untuk berhenti.');

        while (true) {
            try {
                $url = "https://api.telegram.org/bot" . TelegramSetting::getValue('bot_token') . "/getUpdates";
                $url .= "?offset=" . ($lastUpdateId + 1) . "&timeout=30";

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 35);
                $response = curl_exec($ch);
                curl_close($ch);

                $data = json_decode($response, true);

                if ($data['ok'] ?? false) {
                    foreach ($data['result'] as $update) {
                        $updateId = $update['update_id'];
                        if ($updateId > $lastUpdateId) {
                            $lastUpdateId = $updateId;

                            // Proses update via controller
                            $request = new Request($update);
                            $controller->webhook($request);

                            $this->line("  [{$updateId}] Pesan diproses.");
                        }
                    }
                }

                // Sleep sebentar
                usleep(500000); // 0.5 detik
            } catch (\Throwable $e) {
                $this->error("Error: " . $e->getMessage());
                sleep(5);
            }
        }
    }
}
