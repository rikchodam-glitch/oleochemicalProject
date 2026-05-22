<?php

namespace App\Services\Telegram;

use App\Models\TelegramSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected string $token;
    protected string $apiBase;

    public function __construct()
    {
        $this->token = TelegramSetting::getValue('bot_token');
        $this->apiBase = "https://api.telegram.org/bot{$this->token}";
    }

    /**
     * Kirim pesan teks ke chat_id tertentu
     */
    public function sendMessage(string $chatId, string $text, array $extra = []): array
    {
        $response = Http::post("{$this->apiBase}/sendMessage", array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $extra));

        return $response->json();
    }

    /**
     * Kirim aksi typing (memberi indikasi bot sedang mengetik)
     */
    public function sendChatAction(string $chatId, string $action = 'typing'): void
    {
        Http::post("{$this->apiBase}/sendChatAction", [
            'chat_id' => $chatId,
            'action' => $action,
        ]);
    }

    /**
     * Kirim pesan dengan inline keyboard (tombol interaktif)
     * Button format: [['text' => 'Label', 'callback_data' => 'data'], ...]
     */
    public function sendMessageWithKeyboard(string $chatId, string $text, array $buttons, array $extra = []): array
    {
        $keyboard = [
            'inline_keyboard' => array_map(fn($b) => [$b], $buttons),
        ];

        $response = Http::post("{$this->apiBase}/sendMessage", array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ], $extra));
        return $response->json();
    }
    /**
     * Edit pesan (update text) - untuk merespon callback query
     */
    public function editMessageText(string $chatId, int $messageId, string $text, array $extra = []): array
    {
        $response = Http::post("{$this->apiBase}/editMessageText", array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $extra));

        return $response->json();
    }

    /**
     * Jawab callback query (memberi feedback ke user yang tap tombol)
     */
    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): array
    {
        $response = Http::post("{$this->apiBase}/answerCallbackQuery", [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
        ]);

        return $response->json();
    }

    /**
     * Download file dari Telegram (untuk foto)
     */
    public function downloadFile(string $fileId): ?string
    {
        $response = Http::get("{$this->apiBase}/getFile", [
            'file_id' => $fileId,
        ]);
        $data = $response->json();
        if (!$data['ok'] ?? false) return null;

        $filePath = $data['result']['file_path'] ?? null;
        if (!$filePath) return null;

        $downloadUrl = "https://api.telegram.org/file/bot{$this->token}/{$filePath}";
        $fileContent = Http::get($downloadUrl)->body();

        return $fileContent;
    }

    /**
     * Set webhook URL
     */
    public function setWebhook(string $url): array
    {
        $response = Http::post("{$this->apiBase}/setWebhook", [
            'url' => $url,
        ]);

        return $response->json();
    }

    /**
     * Get webhook info
     */
    public function getWebhookInfo(): array
    {
        $response = Http::get("{$this->apiBase}/getWebhookinfo");
        return $response->json();
    }

    /**
     * Delete webhook
     */
    public function deleteWebhook(): array
    {
        $response = Http::post("{$this->apiBase}/deleteWebhook");
        return $response->json();
    }

    /**
     * Get bot info / me
     */
    public function getMe(): array
    {
        $response = Http::get("{$this->apiBase}/getMe");
        return $response->json();
    }

    /**
     * Cek apakah bot aktif
     */
    public function isBotActive(): bool
    {
        if (empty($this->token)) return false;
        $me = $this->getMe();
        return ($me['ok'] ?? false) === true;
    }
}

