<?php

namespace App\Services\AI;

use App\Models\AiProvider;
use App\Models\AiUsageLog;
use App\Models\Asset;
use App\Models\AssetAlias;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AiGatewayService
{
    protected array $providers = [];
    protected ?Employee $employee = null;
    protected ?int $telegramBotLogId = null;
    protected array $fallbackChain = [];

    public function __construct()
    {
        $this->providers = AiProvider::available()->get()->toArray();
    }

    /**
     * Set context untuk logging
     */
    public function withContext(?Employee $employee = null, ?int $telegramBotLogId = null): self
    {
        $this->employee = $employee;
        $this->telegramBotLogId = $telegramBotLogId;
        return $this;
    }

    /**
     * Main method: analisa laporan dengan AI, otomatis fallback ke provider berikutnya
     * Untuk report manager (existing flow - langsung mapping ke asset)
     */
    public function analyzeReport(string $rawText, array $parsedItems, Employee $employee, string $shift, Carbon $date): array
    {
        $systemPrompt = $this->buildSystemPrompt($employee);
        $userPrompt = $this->buildUserPrompt($rawText, $parsedItems, $shift, $date);

        $result = $this->analyze($systemPrompt, $userPrompt);

        if (!$result['success']) {
            // Fallback: return parsed items original
            return [
                'success' => false,
                'items' => $parsedItems,
                'unknown_assets' => [],
                'warnings' => ['AI tidak tersedia: ' . $result['error']],
                'summary' => 'Fallback ke parser standar',
                'provider_used' => null,
                'fallback_chain' => $result['fallback_chain'],
            ];
        }

        $aiData = $result['data'];
        $items = $this->mapAiItemsToReportFormat($aiData['items'] ?? [], $parsedItems);

        return [
            'success' => true,
            'items' => $items,
            'unknown_assets' => $aiData['unknown_assets'] ?? [],
            'ai_notes' => $aiData['ai_notes'] ?? null,
            'fallback_reason' => null,
            'warnings' => $aiData['warnings'] ?? [],
            'summary' => $aiData['summary'] ?? '',
            'provider_used' => $result['provider_used'],
            'fallback_chain' => $result['fallback_chain'],
            'processing_time_ms' => $result['processing_time_ms'],
            'new_aliases' => $aiData['new_aliases'] ?? [],
            'is_ambiguous' => $aiData['is_ambiguous'] ?? false,
            'possible_assets' => $aiData['possible_assets'] ?? [],
            'clarification_question' => $aiData['clarification_question'] ?? null,
        ];
    }

    /**
     * Mode klarifikasi: AI menganalisa laporan ambigu untuk Telegram Bot.
     * Jika confidence < 80% -> return opsi-opsi yang mungkin.
     * Jika lokasi tidak disebut -> tampilkan opsi dari semua kemungkinan.
     */
    public function analyzeWithClarification(string $userText, ?Employee $employee = null): array
    {
        $systemPrompt = $this->buildClarificationPrompt($employee);
        $userPrompt = "Laporan: {$userText}\n\nAnalisa dan berikan opsi terbaik.";

        $result = $this->analyze($systemPrompt, $userPrompt, [
            'temperature' => 0.15,
            'max_tokens' => 1024,
        ]);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => 'AI tidak tersedia: ' . ($result['error'] ?? 'Unknown'),
                'fallback_chain' => $result['fallback_chain'],
            ];
        }

        $data = $result['data'];
        $data['provider_used'] = $result['provider_used'];
        $data['fallback_chain'] = $result['fallback_chain'];
        $data['processing_time_ms'] = $result['processing_time_ms'];

        return $data;
    }

    /**
     * Prompt khusus untuk klarifikasi - AI diminta deteksi ambiguity
     * dan berikan opsi konkret dari database
     */
    protected function buildClarificationPrompt(?Employee $employee): string
    {
        $allAssets = Asset::select('id', 'tech_ident_no', 'equipment_no', 'description')
            ->with(['company:id,name', 'department:id,name', 'area:id,name', 'subArea:id,name'])
            ->limit(150)
            ->get()
            ->groupBy(function($a) { return $a->company->name ?? 'Unknown'; });

        $contextParts = [];
        foreach ($allAssets as $companyName => $companyAssets) {
            $items = $companyAssets->map(function($a) {
                $loc = $a->department->name ?? '-';
                $loc .= ' / ' . ($a->area->name ?? '-');
                $loc .= ' / ' . ($a->subArea->name ?? '-');
                return "  - [{$a->id}] {$a->tech_ident_no} - {$a->description} ($loc)";
            })->implode("\n");
            $contextParts[] = "=== {$companyName} ===\n{$items}";
        }
        $assetContext = implode("\n\n", $contextParts);

        $prompt = "Anda adalah asisten pintar untuk sistem laporan maintenance pabrik oleochemical.
Tugas Anda: menerima laporan mentah dari teknisi, lalu menentukan apakah laporan tersebut bisa langsung dicocokkan dengan asset database.

DATABASE ASSET:
{$assetContext}

INSTRUKSI:
1. Baca laporan teknisi dengan seksama
2. Identifikasi apakah laporan menyebutkan:
   a. Equipment/lokasi yang jelas (bisa langsung cocokkan)
   b. Equipment yang ambigu (banyak kemungkinan)
   c. Lokasi TIDAK disebut sama sekali

3. Hitung confidence (0.0 - 1.0):
   - >= 0.8: yakin, bisa langsung simpan
   - 0.5 - 0.79: agak yakin, tapi perlu klarifikasi
   - < 0.5: tidak yakin, perlu klarifikasi

4. Jika laporan menyebutkan lokasi secara spesifik (nama PT, Dept, Area):
   - Cari asset yang cocok di lokasi tersebut
   - Berikan opsi yang relevan

5. Jika laporan TIDAK menyebutkan lokasi sama sekali:
   - Cari asset dari SEMUA lokasi
   - Tampilkan maksimal 5 opsi terbaik

6. Jika benar-benar tidak ada yang cocok:
   - is_ambiguous = true
   - possible_assets = []
   - clarification_question = 'Tidak ditemukan equipment yang cocok. Jelaskan lebih detail.'

FORMAT OUTPUT (JSON WAJIB):
{
  'is_ambiguous': true/false,
  'confidence': 0.0-1.0,
  'possible_assets': [
    {
      'id': 15,
      'tech_ident_no': 'AC-TF-1-1',
      'description': 'Lampu TL Lab EPE',
      'location': 'PT EPE - QC - Lab - Lt.1',
      'confidence': 0.65
    }
  ],
  'clarification_question': 'Pertanyaan klarifikasi yang akan dikirim ke user...',
  'new_aliases': [],
  'normalized_text': 'Teks laporan yang sudah dikoreksi ejaan',
  'summary': 'Ringkasan analisis...'
}

ATURAN PENTING:
- possible_assets: maksimal 5 opsi, urutkan dari confidence tertinggi
- clarification_question HARUS dalam Bahasa Indonesia yang ramah
- Jika confidence >= 0.8: is_ambiguous = false, isi langsung asset_id
- Jika ada alias yang cocok dari database, prioritaskan
- Output HANYA JSON, tidak ada teks lain";

        return $prompt;
    }

    /**
     * Kirim prompt ke AI dengan fallback chain
     */
    public function analyze(string $systemPrompt, string $userPrompt, array $options = []): array
    {
        $lastError = null;
        $this->fallbackChain = [];
        $startTime = microtime(true);

        foreach ($this->providers as $provider) {
            $this->fallbackChain[] = $provider['name'];

            try {
                // Cek rate limit
                if (!$this->checkRateLimit($provider)) {
                    Log::info("AI Rate limit reached for {$provider['name']}, trying next...");
                    continue;
                }

                // Cek quota harian
                if (!$this->checkDailyQuota($provider)) {
                    Log::info("AI Daily quota exhausted for {$provider['name']}, trying next...");
                    continue;
                }

                // Cek quota bulanan
                if (!$this->checkMonthlyQuota($provider)) {
                    Log::info("AI Monthly quota exhausted for {$provider['name']}, trying next...");
                    continue;
                }

                // Kirim request
                $decryptedKey = $this->decryptKey($provider['api_key']);
                $provider['api_key'] = $decryptedKey;

                $result = $this->callProvider($provider, $systemPrompt, $userPrompt, $options);

                if ($result['success']) {
                    $usage = $result['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

                    // Update provider stats
                    $this->updateProviderStats($provider['id'], $usage);

                    // Log sukses
                    $this->logUsage([
                        'ai_provider_id' => $provider['id'],
                        'telegram_bot_log_id' => $this->telegramBotLogId,
                        'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                        'completion_tokens' => $usage['completion_tokens'] ?? 0,
                        'total_tokens' => $usage['total_tokens'] ?? 0,
                        'processing_time_ms' => (int)((microtime(true) - $startTime) * 1000),
                        'model_used' => $provider['model'],
                        'status' => count($this->fallbackChain) > 1 ? 'fallback' : 'success',
                        'fallback_chain' => implode('→', $this->fallbackChain),
                        'estimated_cost' => $this->estimateCost($usage['total_tokens'] ?? 0, $provider['provider']),
                    ]);

                    return [
                        'success' => true,
                        'data' => $result['data'],
                        'provider_used' => $provider['name'],
                        'fallback_chain' => $this->fallbackChain,
                        'processing_time_ms' => (int)((microtime(true) - $startTime) * 1000),
                    ];
                }

                $lastError = $result['error'] ?? 'Unknown error';

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning("AI Provider {$provider['name']} failed: {$e->getMessage()}");

                $this->logUsage([
                    'ai_provider_id' => $provider['id'],
                    'telegram_bot_log_id' => $this->telegramBotLogId,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'fallback_chain' => implode('→', $this->fallbackChain),
                ]);
            }
        }

        return [
            'success' => false,
            'error' => "Semua AI provider gagal. Terakhir: {$lastError}",
            'fallback_chain' => $this->fallbackChain,
            'processing_time_ms' => (int)((microtime(true) - $startTime) * 1000),
        ];
    }

    /**
     * Panggil API sesuai tipe provider
     */
    protected function callProvider(array $provider, string $systemPrompt, string $userPrompt, array $options): array
    {
        return match($provider['provider']) {
            'groq' => $this->callGroq($provider, $systemPrompt, $userPrompt, $options),
            'openai' => $this->callOpenAI($provider, $systemPrompt, $userPrompt, $options),
            'ollama' => $this->callOllama($provider, $systemPrompt, $userPrompt, $options),
            default => throw new \Exception("Provider {$provider['provider']} belum didukung"),
        };
    }

    /**
     * Panggil Groq API
     */
    protected function callGroq(array $provider, string $systemPrompt, string $userPrompt, array $options): array
    {
        $baseUrl = $provider['api_base_url'] ?? 'https://api.groq.com/openai/v1';

        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => "Bearer {$provider['api_key']}",
                'Content-Type' => 'application/json',
            ])->post("{$baseUrl}/chat/completions", [
                'model' => $provider['model'] ?? 'llama-70b-8192',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => $options['temperature'] ?? 0.1,
                'max_tokens' => $options['max_tokens'] ?? 2048,
                'response_format' => $options['response_format'] ?? ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            $error = $response->json();
            $statusCode = $response->status();
            $errorMsg = $error['error']['message'] ?? 'Groq API error';

            // Rate limit atau quota habis
            if ($statusCode === 429 || $statusCode === 402) {
                // Tandai provider sebagai habis untuk sementara
                AiProvider::where('id', $provider['id'])->update([
                    'health_status' => 'unhealthy',
                    'last_health_check_at' => now(),
                ]);
            }

            return [
                'success' => false,
                'error' => "[{$statusCode}] {$errorMsg}",
            ];
        }

        $body = $response->json();

        // Parse JSON response dari AI
        $contentRaw = $body['choices'][0]['message']['content'] ?? '{}';
        $content = json_decode($contentRaw, true);

        if (!$content) {
            // Coba parse dengan regex jika JSON tidak valid
            preg_match('/\{.*\}/s', $contentRaw, $matches);
            $content = !empty($matches) ? json_decode($matches[0], true) : null;
        }

        return [
            'success' => true,
            'data' => $content ?: [],
            'usage' => $body['usage'] ?? [],
            'raw' => $body,
        ];
    }

    /**
     * Panggil OpenAI API (fallback)
     */
    protected function callOpenAI(array $provider, string $systemPrompt, string $userPrompt, array $options): array
    {
        $baseUrl = $provider['api_base_url'] ?? 'https://api.openai.com/v1';

        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => "Bearer {$provider['api_key']}",
                'Content-Type' => 'application/json',
            ])->post("{$baseUrl}/chat/completions", [
                'model' => $provider['model'] ?? 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => $options['temperature'] ?? 0.1,
                'max_tokens' => $options['max_tokens'] ?? 2048,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            $error = $response->json();
            return [
                'success' => false,
                'error' => $error['error']['message'] ?? 'OpenAI API error',
            ];
        }

        $body = $response->json();
        $content = json_decode($body['choices'][0]['message']['content'] ?? '{}', true);

        return [
            'success' => true,
            'data' => $content ?: [],
            'usage' => $body['usage'] ?? [],
            'raw' => $body,
        ];
    }

    /**
     * Panggil Ollama local (gratis, backup darurat)
     */
    protected function callOllama(array $provider, string $systemPrompt, string $userPrompt, array $options): array
    {
        $baseUrl = $provider['api_base_url'] ?? 'http://localhost:11434';

        $response = Http::timeout(60)
            ->post("{$baseUrl}/api/chat", [
                'model' => $provider['model'] ?? 'llama3.2',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'stream' => false,
                'options' => [
                    'temperature' => $options['temperature'] ?? 0.1,
                ],
                'format' => 'json',
            ]);

        if (!$response->successful()) {
            return ['success' => false, 'error' => 'Ollama local tidak tersedia'];
        }

        $body = $response->json();
        $content = json_decode($body['message']['content'] ?? '{}', true);

        return [
            'success' => true,
            'data' => $content ?: [],
            'usage' => [
                'prompt_tokens' => $body['prompt_eval_count'] ?? 0,
                'completion_tokens' => $body['eval_count'] ?? 0,
                'total_tokens' => ($body['prompt_eval_count'] ?? 0) + ($body['eval_count'] ?? 0),
            ],
            'raw' => $body,
        ];
    }

    /**
     * Bangun system prompt dengan konteks asset database
     */
    protected function buildSystemPrompt(Employee $employee): string
    {
        // Ambil asset untuk konteks (batasi 100 untuk prompt)
        // Cari department_id yang cocok dengan department string employee
        $department = \App\Models\Department::where('name', $employee->department)->first();
        $departmentId = $department ? $department->id : null;

        $assets = Asset::select('id', 'tech_ident_no', 'equipment_no', 'description')
            ->with(['area:id,name', 'subArea:id,name'])
            ->when($departmentId, function($q) use ($departmentId) {
                return $q->where('department_id', $departmentId);
            })
            ->limit(100)
            ->get();

        $assetContext = $assets->map(fn($a) =>
            "\"{$a->tech_ident_no}\" - {$a->description} (" . ($a->area->name ?? '-') . " - " . ($a->subArea->name ?? '-') . ")"
        )->implode("\n");

        // Ambil alias yang sudah ada
        $aliases = AssetAlias::with('asset')
            ->forEmployee($employee->id)
            ->orderBy('usage_count', 'desc')
            ->limit(50)
            ->get();

        $aliasContext = '';
        if ($aliases->isNotEmpty()) {
            $aliasContext = "\n\nRiwayat mapping yang sudah dikenal:\n" .
                $aliases->map(fn($a) =>
                    "\"{$a->alias}\" → \"{$a->asset->tech_ident_no}\" (confidence: {$a->confidence_score}%, used: {$a->usage_count}x)"
                )->implode("\n");
        }

        return <<<PROMPT
Anda adalah asisten analisis laporan maintenance pabrik oleochemical.

TUGAS ANDA:
1. Terima teks laporan kasar dari teknisi
2. Koreksi ejaan/penamaan equipment jika ada typo
3. Cocokkan setiap item dengan database asset berikut (prioritas tech_ident_no):

DATABASE ASSET:
{$assetContext}
{$aliasContext}

FORMAT OUTPUT (JSON WAJIB):
{
  "items": [
    {
      "original_text": "teks asli dari teknisi",
      "action_clean": "teks yang sudah dikoreksi",
      "suggested_asset_id": null,
      "suggested_tech_ident_no": null,
      "confidence": 0,
      "status": "done",
      "notes": "Penjelasan kenapa mapping berhasil/gagal"
    }
  ],
  "unknown_assets": [],
  "new_aliases": [],
  "warnings": [],
  "summary": "",
  "ai_notes": "Analisis keseluruhan: apa yang berhasil dicocokkan, apa yang tidak, dan saran untuk admin"
}

ATURAN:
- confidence: 0.0 - 1.0 (1.0 = sangat yakin)
- Jika confidence >= 0.8, isi suggested_asset_id (HARUS numeric ID, bukan tech_ident_no) dan suggested_tech_ident_no
- Jika confidence < 0.8, biarkan null dan tambahkan ke warnings
- suggested_asset_id HARUS numeric (integer), contoh: 1, 15, 42. Jangan pakai string seperti "AC-TF-1-1"
- suggested_tech_ident_no diisi dengan string tech_ident_no, contoh: "AC-TF-1-1"
- CATATAN: asset_id adalah ID numerik dari database, bukan tech_ident_no
- new_aliases: jika ada teks yang jelas merujuk ke asset tertentu walau tidak exact match
  format: [{"text": "Pompa 1", "asset_id": 15, "confidence": 0.85}]
  asset_id di new_aliases juga HARUS numeric ID
- status: done / continue / pending
- Output HARUS valid JSON, tidak ada teks lain di luar JSON
PROMPT;
    }

    /**
     * Bangun user prompt dari laporan spesifik
     */
    protected function buildUserPrompt(string $rawText, array $parsedItems, string $shift, Carbon $date): string
    {
        $itemsText = collect($parsedItems)->map(function($item, $i) {
            return ($i + 1) . ". {$item['action']} ({$item['status']})";
        })->implode("\n");

        return <<<PROMPT
Laporan maintenance:
Shift: {$shift}
Tanggal: {$date->format('d/m/Y')}

Items:
{$itemsText}

Teks asli teknisi:
{$rawText}
PROMPT;
    }

    /**
     * Map hasil AI ke format item laporan
     */
    protected function mapAiItemsToReportFormat(array $aiItems, array $originalItems): array
    {
        if (empty($aiItems)) {
            return $originalItems;
        }

        return collect($aiItems)->map(function($item) {
            // Pastikan suggested_asset_id adalah numeric
            $assetId = $item['suggested_asset_id'] ?? null;
            if ($assetId && !is_numeric($assetId)) {
                // Jika AI mengembalikan string tech_ident_no, cari ID-nya
                $asset = \App\Models\Asset::where('tech_ident_no', $assetId)->first();
                $assetId = $asset ? $asset->id : null;
            }

            return [
                'action' => $item['action_clean'] ?? $item['original_text'],
                'status' => $item['status'] ?? 'done',
                'suggested_asset_id' => $assetId ? (int)$assetId : null,
                'suggested_tech_ident_no' => $item['suggested_tech_ident_no'] ?? null,
                'confidence' => $item['confidence'] ?? 0,
                'notes' => $item['notes'] ?? null,
            ];
        })->toArray();
    }

    /**
     * Decrypt API key
     */
    protected function decryptKey(string $encryptedKey): string
    {
        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($encryptedKey);
        } catch (\Throwable $e) {
            // Jika gagal decrypt, coba dengan helper decrypt
            try {
                return decrypt($encryptedKey);
            } catch (\Throwable $e2) {
                // Return as-is jika memang belum terenkripsi
                return $encryptedKey;
            }
        }
    }

    /**
     * Cek rate limit per menit
     */
    protected function checkRateLimit(array &$provider): bool
    {
        $now = now();

        if (!$provider['minute_reset_at'] ||
            Carbon::parse($provider['minute_reset_at'])->diffInMinutes($now) >= 1) {
            $provider['requests_this_minute'] = 0;
            $provider['minute_reset_at'] = $now;

            AiProvider::where('id', $provider['id'])
                ->update(['requests_this_minute' => 0, 'minute_reset_at' => $now]);
        }

        return $provider['requests_this_minute'] < $provider['requests_per_minute'];
    }

    /**
     * Cek quota harian (TPD) — auto reset jika sudah beda hari
     */
    protected function checkDailyQuota(array &$provider): bool
    {
        if (!$provider['max_daily_tokens']) return true;

        $now = now();

        // Reset jika belum pernah atau sudah beda hari
        if (!$provider['daily_reset_at'] ||
            !Carbon::parse($provider['daily_reset_at'])->isSameDay($now)) {
            $provider['current_daily_tokens'] = 0;
            $provider['daily_reset_at'] = $now;

            AiProvider::where('id', $provider['id'])
                ->update(['current_daily_tokens' => 0, 'daily_reset_at' => $now]);
        }

        return $provider['current_daily_tokens'] < $provider['max_daily_tokens'];
    }

    /**
     * Cek quota bulanan — auto reset jika sudah beda bulan
     */
    protected function checkMonthlyQuota(array &$provider): bool
    {
        if (!$provider['max_monthly_tokens']) return true;

        $now = now();

        // Reset jika belum pernah atau sudah beda bulan
        if (!$provider['month_reset_at'] ||
            !Carbon::parse($provider['month_reset_at'])->isSameMonth($now)) {
            $provider['current_month_tokens'] = 0;
            $provider['month_reset_at'] = $now;

            AiProvider::where('id', $provider['id'])
                ->update(['current_month_tokens' => 0, 'month_reset_at' => $now]);
        }

        return $provider['current_month_tokens'] < $provider['max_monthly_tokens'];
    }

    /**
     * Update statistik provider (termasuk daily tokens)
     */
    protected function updateProviderStats(int $providerId, array $usage): void
    {
        AiProvider::where('id', $providerId)->update([
            'total_requests' => DB::raw('total_requests + 1'),
            'total_tokens_used' => DB::raw('total_tokens_used + ' . ($usage['total_tokens'] ?? 0)),
            'requests_this_minute' => DB::raw('requests_this_minute + 1'),
            'current_month_tokens' => DB::raw('current_month_tokens + ' . ($usage['total_tokens'] ?? 0)),
            'current_daily_tokens' => DB::raw('current_daily_tokens + ' . ($usage['total_tokens'] ?? 0)),
            'last_used_at' => now(),
            'health_status' => 'healthy',
        ]);
    }

    /**
     * Log pemakaian
     */
    protected function logUsage(array $data): void
    {
        try {
            AiUsageLog::create($data);
        } catch (\Throwable $e) {
            Log::warning("Gagal log AI usage: {$e->getMessage()}");
        }
    }

    /**
     * Estimasi biaya
     */
    protected function estimateCost(int $totalTokens, string $provider): float
    {
        $rates = [
            'groq' => 0.00059,
            'openai' => 0.0015,
            'anthropic' => 0.003,
            'ollama' => 0,
        ];

        return ($totalTokens / 1000) * ($rates[$provider] ?? 0);
    }

    /**
     * Test koneksi ke satu provider
     */
    public function testProvider(int $providerId): array
    {
        $provider = AiProvider::find($providerId);
        if (!$provider) {
            return ['success' => false, 'error' => 'Provider tidak ditemukan'];
        }

        $providerArr = $provider->toArray();
        $providerArr['api_key'] = $this->decryptKey($provider->api_key);

        try {
            $result = $this->callProvider(
                $providerArr,
                'Kamu adalah AI Test. Balas dengan satu kata: OK. Output JSON: {"status":"ok"}',
                'Test koneksi',
                ['response_format' => ['type' => 'json_object']]
            );

            if ($result['success']) {
                $provider->update(['health_status' => 'healthy', 'last_health_check_at' => now()]);
                return [
                    'success' => true,
                    'message' => "✅ {$provider->name} berfungsi baik!",
                    'model' => $provider->model,
                    'response_time_ms' => $result['usage']['total_tokens'] ?? 0,
                ];
            }

            $provider->update(['health_status' => 'unhealthy', 'last_health_check_at' => now()]);
            return ['success' => false, 'error' => $result['error']];

        } catch (\Throwable $e) {
            $provider->update(['health_status' => 'unhealthy', 'last_health_check_at' => now()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get status semua provider (untuk dashboard)
     */
    public static function getProvidersStatus(): array
    {
        return AiProvider::orderBy('priority_order')
            ->get()
            ->map(function($provider) {
                $sisa = $provider->getRemainingMonthlyTokens();
                $persen = $provider->getTokenUsagePercentage();
                $sisaDaily = $provider->getRemainingDailyTokens();
                $persenDaily = $provider->getDailyTokenUsagePercentage();

                return [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'provider' => $provider->provider,
                    'model' => $provider->model,
                    'priority' => $provider->priority_order,
                    'is_active' => $provider->is_active,
                    'total_requests' => $provider->total_requests,
                    'total_tokens' => $provider->total_tokens_used,
                    // Daily quota
                    'max_daily_tokens' => $provider->max_daily_tokens,
                    'current_daily_tokens' => $provider->current_daily_tokens,
                    'sisa_daily_tokens' => $sisaDaily,
                    'persen_daily_sisa' => $persenDaily !== null ? (100 - $persenDaily) : null,
                    // Monthly quota
                    'max_monthly_tokens' => $provider->max_monthly_tokens,
                    'current_month_tokens' => $provider->current_month_tokens,
                    'sisa_tokens' => $sisa,
                    'persen_sisa' => $persen !== null ? (100 - $persen) : null,
                    'last_used' => $provider->last_used_at ? $provider->last_used_at->diffForHumans() : 'Belum pernah',
                    'last_health_check' => $provider->last_health_check_at ? $provider->last_health_check_at->diffForHumans() : 'Belum dicek',
                    'health_status' => $provider->health_status,
                    'status_label' => $provider->status_label,
                    'status_color' => $provider->status_color,
                    'has_quota' => $provider->hasAvailableQuota(),
                ];
            })->toArray();
    }
}
