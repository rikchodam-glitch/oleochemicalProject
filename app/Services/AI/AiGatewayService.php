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
     * Kirim SATU ITEM per panggilan ke AI.
     */
    public function analyzeWithClarification(string $userText, ?Employee $employee = null): array
    {
        $systemPrompt = $this->buildClarificationPrompt($employee, $userText);
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

        // Pastikan setiap item punya field is_area_work (default false)
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as &$item) {
                $item['is_area_work'] = $item['is_area_work'] ?? false;
            }
        }

        // Fallback: jika AI return empty possible_assets dan is_area_work false,
        // generate possible_assets dari keyword di database
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as &$item) {
                $possibleAssets = $item['possible_assets'] ?? [];
                $isAreaWork = $item['is_area_work'] ?? false;

                if (empty($possibleAssets) && !$isAreaWork && ($item['confidence'] ?? 0) < 0.8) {
                    $item['possible_assets'] = $this->fallbackSearchAssets(
                        $item['original_text'] ?? $item['action_clean'] ?? ''
                    );
                    $item['notes'] = ($item['notes'] ?? '') . ' | Fallback: AI tidak memberikan opsi, dicari dari database';
                }
            }
        }

        return $data;
    }

    /**
     * Prompt khusus untuk klarifikasi — filter asset berdasarkan kata kunci userText.
     * Kirim 30 asset relevan ke AI. Response HARUS items array (multi-item).
     */
    protected function buildClarificationPrompt(?Employee $employee, string $userText = ''): string
    {
        $keywords = $this->extractKeywords($userText);

        // === EKSTRAK KODE AREA (RG1, BD1, BD2, EPE, CES, TF1, TF2) ===
        $areaCodes = [];
        preg_match_all('/(?<![A-Za-z0-9])(RG[1-9]|BD[1-9]|EPE|CES|TF[1-9])(?![A-Za-z0-9])/i', $userText, $areaMatches);
        foreach ($areaMatches[0] as $ac) {
            $areaCodes[] = strtoupper($ac);
        }

        // === CARI ASSET: PRIORITAS AREA DULU ===
        $areaAssets = collect();
        $keywordAssets = collect();

        if (!empty($areaCodes)) {
            // 1. Cari asset yang areanya cocok dengan kode area
            $areaAssets = Asset::select('id', 'tech_ident_no', 'equipment_no', 'description')
                ->with(['company:id,name', 'department:id,name', 'area:id,name', 'subArea:id,name'])
                ->where(function($q) use ($areaCodes) {
                    foreach ($areaCodes as $ac) {
                        $q->orWhereHas('area', fn($sq) => $sq->where('name', $ac))
                          ->orWhereHas('subArea', fn($sq) => $sq->where('name', $ac));
                    }
                })
                // Juga filter dengan keyword jika ada
                ->when(!empty($keywords), function($q) use ($keywords) {
                    $q->where(function($sub) use ($keywords) {
                        foreach ($keywords as $kw) {
                            $sub->orWhere('description', 'like', '%' . $kw . '%')
                                ->orWhere('tech_ident_no', 'like', '%' . $kw . '%')
                                ->orWhere('equipment_no', 'like', '%' . $kw . '%');
                        }
                    });
                })
                ->limit(20)
                ->get();
        }

        // 2. Cari berdasarkan keyword (tanpa filter area) sebagai pelengkap
        if (!empty($keywords)) {
            $keywordQuery = Asset::select('id', 'tech_ident_no', 'equipment_no', 'description')
                ->with(['company:id,name', 'department:id,name', 'area:id,name', 'subArea:id,name'])
                ->where(function($q) use ($keywords) {
                    foreach ($keywords as $kw) {
                        $q->orWhere('description', 'like', '%' . $kw . '%')
                          ->orWhere('tech_ident_no', 'like', '%' . $kw . '%')
                          ->orWhere('equipment_no', 'like', '%' . $kw . '%');
                    }
                })
                ->limit(20)
                ->get();

            // Gabungkan, tanpa duplikasi
            $existingIds = $areaAssets->pluck('id')->toArray();
            foreach ($keywordQuery as $asset) {
                if (!in_array($asset->id, $existingIds)) {
                    $keywordAssets->push($asset);
                }
            }
        }

        // 3. Fallback jika semua kosong
        if ($areaAssets->isEmpty() && $keywordAssets->isEmpty()) {
            $keywordAssets = Asset::select('id', 'tech_ident_no', 'equipment_no', 'description')
                ->with(['company:id,name', 'department:id,name', 'area:id,name', 'subArea:id,name'])
                ->limit(20)
                ->get();
        }

        // === BANGUN KONTEKS ASSET DENGAN LABEL PRIORITAS ===
        $contextParts = [];
        $allAssetIds = [];

        // Kelompokkan asset berdasarkan area
        $groupedByArea = $areaAssets->groupBy(function($a) {
            return $a->area->name ?? 'Tanpa Area';
        });

        foreach ($groupedByArea as $areaName => $groupAssets) {
            $items = $groupAssets->map(function($a) use (&$allAssetIds) {
                $locParts = [];
                if ($a->department) $locParts[] = $a->department->name;
                if ($a->area) $locParts[] = $a->area->name;
                if ($a->subArea) $locParts[] = $a->subArea->name;
                $loc = implode(' - ', $locParts);
                $allAssetIds[] = $a->id;
                return '  [' . $a->id . '] ' . $a->tech_ident_no . ' - ' . $a->description . ($loc ? ' (' . $loc . ')' : '');
            })->implode("\n");
            $areaLabel = in_array(strtoupper($areaName), $areaCodes) ? "[AREA DIMAKSUD] {$areaName}" : "Area {$areaName}";
            $contextParts[] = '=== ' . $areaLabel . " ===\n" . $items;
        }

        // Tambahkan keyword-based assets (dari area berbeda)
        if ($keywordAssets->isNotEmpty()) {
            $keywordItems = $keywordAssets->filter(function($a) use (&$allAssetIds) {
                return !in_array($a->id, $allAssetIds);
            })->map(function($a) {
                $locParts = [];
                if ($a->department) $locParts[] = $a->department->name;
                if ($a->area) $locParts[] = $a->area->name;
                if ($a->subArea) $locParts[] = $a->subArea->name;
                $loc = implode(' - ', $locParts);
                return '  [' . $a->id . '] ' . $a->tech_ident_no . ' - ' . $a->description . ($loc ? ' (' . $loc . ')' : '');
            })->implode("\n");

            if (!empty(trim($keywordItems))) {
                $contextParts[] = "=== AREA LAIN (keyword mirip) ===\n" . $keywordItems;
            }
        }

        $assetContext = implode("\n\n", $contextParts);

        $prompt = 'Anda adalah asisten pintar untuk sistem laporan maintenance pabrik oleochemical.' . "\n\n";
        $prompt .= 'Tugas Anda: Menerima teks laporan maintenance, lalu cari equipment yang PALING COCOK di database.' . "\n\n";
        $prompt .= "DATABASE ASSET:\n" . $assetContext . "\n\n";

        // === FEEDBACK: mapping yang pernah ditolak admin ===
        $rejectedAliases = \Illuminate\Support\Facades\Cache::get('ai_feedback_rejected_aliases', []);
        $relevantRejections = [];
        foreach ($rejectedAliases as $ra) {
            foreach ($keywords as $kw) {
                if (stripos($ra['alias'], $kw) !== false || stripos($kw, $ra['alias']) !== false) {
                    $relevantRejections[] = $ra;
                    break;
                }
            }
        }
        if (!empty($relevantRejections)) {
            $prompt .= "PERINGATAN — mapping berikut PERNAH DITOLAK ADMIN, JANGAN GUN A KAN:\n";
            foreach ($relevantRejections as $rr) {
                $prompt .= "- JANGAN mapping \"{$rr['alias']}\" ke {$rr['wrong_asset_code']} karena: {$rr['reason']}\n";
            }
            $prompt .= "\n";
        }

        $prompt .= 'INSTRUKSI UTAMA — PRIORITAS LOKASI:' . "\n";
        $prompt .= '1. User menyebut area/lokasi: ' . (implode(', ', $areaCodes) ?: 'tidak ada') . "\n";
        if (!empty($areaCodes)) {
            $prompt .= '2. PRIORITASKAN asset dari bagian "[AREA DIMAKSUD]" karena lokasinya cocok dengan yang disebut user' . "\n";
            $prompt .= '3. Asset dari "AREA LAIN" adalah area berbeda — beri confidence RENDAH (< 0.4) kecuali sangat cocok' . "\n";
            $prompt .= '4. Jika ada asset dari AREA DIMAKSUD yang cocok jenisnya, gunakan itu sebagai opsi utama (confidence tinggi)' . "\n";
        } else {
            $prompt .= '2. Karena tidak ada area disebut, cari berdasarkan jenis equipment (pompa, motor, AC, dll)' . "\n";
        }
        $prompt .= '5. Hitung confidence untuk setiap item (0.0-1.0):' . "\n";
        $prompt .= '   - >= 0.8: isi suggested_asset_id + suggested_tech_ident_no' . "\n";
        $prompt .= '   - < 0.8: isi possible_assets dengan opsi-opsi (min 1, maks 5)' . "\n";
        $prompt .= '6. SETIAP item WAJIB punya possible_assets (min 1 opsi), jangan kosongkan' . "\n";
        $prompt .= '7. PEKERJAAN AREA: Jika teks menyebut pekerjaan bangunan/lokasi (lantai, dinding,' . "\n";
        $prompt .= '   atap, saluran, taman, pagar, plafon, cat, kaca, pintu, jendela) dan TIDAK ADA' . "\n";
        $prompt .= '   kaitannya dengan equipment spesifik, set is_area_work = true' . "\n";
        $prompt .= '   dan kosongkan possible_assets (array kosong []).' . "\n";
        $prompt .= '   Contoh: "Ganti lantai Lab EPE", "Pengecatan dinding gudang" -> is_area_work = true' . "\n\n";
        $prompt .= "FORMAT OUTPUT (JSON WAJIB):\n";
        $prompt .= "{\n";
        $prompt .= '  "items": [' . "\n";
        $prompt .= '    {' . "\n";
        $prompt .= '      "original_text": "Perbaiki lampu Lab EPE",' . "\n";
        $prompt .= '      "action_clean": "Perbaiki lampu Lab EPE",' . "\n";
        $prompt .= '      "confidence": 0.65,' . "\n";
        $prompt .= '      "suggested_asset_id": null,' . "\n";
        $prompt .= '      "suggested_tech_ident_no": null,' . "\n";
        $prompt .= '      "is_area_work": false,' . "\n";
        $prompt .= '      "possible_assets": [' . "\n";
        $prompt .= '        {' . "\n";
        $prompt .= '          "id": 15,' . "\n";
        $prompt .= '          "tech_ident_no": "AC-TF-1-1",' . "\n";
        $prompt .= '          "description": "Lampu TL Lab EPE",' . "\n";
        $prompt .= '          "location": "PT EPE - QC - Lab - Lt.1",' . "\n";
        $prompt .= '          "confidence": 0.65' . "\n";
        $prompt .= '        }' . "\n";
        $prompt .= '      ],' . "\n";
        $prompt .= '      "clarification_question": "Pilih equipment yang dimaksud:",' . "\n";
        $prompt .= '      "notes": "Penjelasan item ini"' . "\n";
        $prompt .= '    }' . "\n";
        $prompt .= '  ],' . "\n";
        $prompt .= '  "summary": "ringkasan analisis"' . "\n";
        $prompt .= '}' . "\n\n";
        $prompt .= 'ATURAN PENTING:' . "\n";
        $prompt .= '- items adalah ARRAY, jumlahnya sesuai item laporan' . "\n";
        $prompt .= '- possible_assets WAJIB minimal 1 opsi per item, KECUALI is_area_work = true' . "\n";
        $prompt .= '- is_area_work: true jika ini pekerjaan area/lokasi tanpa equipment spesifik' . "\n";
        $prompt .= '- Output HANYA JSON, tidak ada teks lain';

        return $prompt;
    }

    /**
     * Ekstrak kata kunci dari teks laporan
     */
    protected function extractKeywords(string $text): array
{
    $keywords = [];

    // Cari kata yang mengandung angka (kode equipment seperti 6163P14, BD1)
    preg_match_all('/[A-Za-z]+[\d]+[A-Za-z0-9\-]*/', $text, $codeMatches);
    foreach ($codeMatches[0] as $code) {
        $keywords[] = strtolower($code);
    }

    // Cari kata huruf >= 3 karakter
    preg_match_all('/[A-Za-z]{3,}/', $text, $wordMatches);
    foreach ($wordMatches[0] as $w) {
        $keywords[] = strtolower($w);
    }

    // Stop words (hanya kata umum, tanpa istilah teknis)
    $stopWords = ['dan', 'atau', 'yang', 'di', 'ke', 'dari', 'dengan', 'untuk', 'pada',
                   'ini', 'itu', 'saya', 'kami', 'the', 'and', 'for', 'are', 'but', 'not',
                   'you', 'all', 'any', 'can', 'had', 'her', 'was', 'one', 'our', 'out',
                   'has', 'have', 'been'];

    $keywords = array_unique(array_diff($keywords, $stopWords));

    // Prioritas: kode equipment (mengandung angka) di depan
    $codes = array_filter($keywords, fn($k) => preg_match('/\d/', $k));
    $words = array_filter($keywords, fn($k) => !preg_match('/\d/', $k));

    return array_values(array_merge($codes, $words));
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
     * Fallback search: jika AI tidak memberikan possible_assets,
     * cari equipment dari database berdasarkan keyword
     */
    protected function fallbackSearchAssets(string $text): array
    {
        $keywords = $this->extractKeywords($text);
        $results = [];

        if (empty($keywords)) {
            return $results;
        }

        $assets = Asset::select('id', 'tech_ident_no', 'equipment_no', 'description')
            ->with(['company:id,name', 'department:id,name', 'area:id,name', 'subArea:id,name'])
            ->where(function($q) use ($keywords) {
                foreach ($keywords as $kw) {
                    $q->orWhere('description', 'like', '%' . $kw . '%');
                    $q->orWhere('tech_ident_no', 'like', '%' . $kw . '%');
                }
            })
            ->limit(5)
            ->get();

        foreach ($assets as $asset) {
            $locParts = [];
            if ($asset->department) $locParts[] = $asset->department->name;
            if ($asset->area) $locParts[] = $asset->area->name;
            if ($asset->subArea) $locParts[] = $asset->subArea->name;
            $loc = implode(' - ', $locParts);

            $results[] = [
                'id' => $asset->id,
                'tech_ident_no' => $asset->tech_ident_no ?? '',
                'description' => $asset->description ?? '',
                'location' => $loc,
                'confidence' => 0.5,
            ];
        }

        return $results;
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

    /**
     * Kirim feedback rejection dari admin ke AI untuk pembelajaran.
     * Menyimpan feedback ke database log untuk referensi di masa depan.
     */
    public function sendFeedback(array $feedback): void
    {
        try {
            // Simpan ke database log AI untuk tracking
            $this->logUsage([
                'ai_provider_id' => null,
                'telegram_bot_log_id' => null,
                'status' => 'feedback',
                'error_message' => json_encode($feedback),
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'estimated_cost' => 0,
            ]);

            // Untuk feedback alias rejection, kita simpan sebagai catatan
            // yang nantinya bisa dibaca oleh AI saat buildClarificationPrompt
            if (($feedback['type'] ?? '') === 'alias_rejected') {
                $alias = $feedback['alias'] ?? '';
                $reason = $feedback['reason'] ?? '';
                $wrongCode = $feedback['wrong_asset_code'] ?? '';

                Log::info("AI FEEDBACK: Alias '{$alias}' -> {$wrongCode} DITOLAK. Alasan: {$reason}");

                // Cache feedback ini agar bisa dibaca oleh prompt AI
                $cacheKey = 'ai_feedback_rejected_aliases';
                $rejectedAliases = \Illuminate\Support\Facades\Cache::get($cacheKey, []);
                $rejectedAliases[$alias] = [
                    'alias' => $alias,
                    'wrong_asset_code' => $wrongCode,
                    'reason' => $reason,
                    'rejected_at' => $feedback['rejected_at'] ?? now()->toIso8601String(),
                ];
                // Simpan max 100 feedback terakhir
                if (count($rejectedAliases) > 100) {
                    array_shift($rejectedAliases);
                }
                \Illuminate\Support\Facades\Cache::forever($cacheKey, $rejectedAliases);
            }
        } catch (\Throwable $e) {
            Log::warning("Gagal menyimpan feedback AI: {$e->getMessage()}");
        }
    }
}
