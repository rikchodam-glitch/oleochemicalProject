<?php

namespace App\Services\Telegram;

use App\Models\MaintenanceReport;
use Illuminate\Support\Facades\Storage;

class TelegramPhotoHandler
{
    protected TelegramService $telegram;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
    }

    /**
     * Download dan simpan foto dari Telegram ke storage lokal
     */
    public function handlePhoto(array $photoData, string $chatId): ?string
    {
        // Ambil file_id dengan resolusi tertinggi (terakhir dalam array)
        $photos = $photoData['photo'] ?? [];
        if (empty($photos)) return null;

        $bestPhoto = end($photos);
        $fileId = $bestPhoto['file_id'];

        // Download file dari Telegram
        $fileContent = $this->telegram->downloadFile($fileId);
        if (!$fileContent) return null;

        // Generate unique filename
        $extension = 'jpg'; // Telegram selalu kirim JPEG
        $filename = 'telegram_' . $chatId . '_' . time() . '_' . uniqid() . '.' . $extension;

        // Simpan ke storage
        $path = 'report-documents/' . $filename;
        Storage::disk('public')->put($path, $fileContent);

        return $path;
    }

    /**
     * Attach foto ke laporan yang sudah ada
     */
    public function attachPhotoToReport(MaintenanceReport $report, string $photoPath): MaintenanceReport
    {
        $documents = $report->documents ? json_decode($report->documents, true) : [];
        $documents[] = $photoPath;
        $report->update([
            'documents' => json_encode($documents),
        ]);

        return $report->fresh();
    }
}
