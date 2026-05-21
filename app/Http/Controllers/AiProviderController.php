<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\AiUsageLog;
use App\Models\AssetAlias;
use App\Services\AI\AiGatewayService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class AiProviderController extends Controller
{
    protected AiGatewayService $gateway;

    public function __construct()
    {
        $this->gateway = new AiGatewayService();
    }

    /**
     * Dashboard panel AI Provider
     */
    public function index()
    {
        $providers = AiGatewayService::getProvidersStatus();

        $recentLogs = AiUsageLog::with('provider')
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->orderBy('created_at', 'desc')
            ->take(50)
            ->get();

        // Statistik 24 jam
        $stats24h = [
            'total_requests' => AiUsageLog::where('created_at', '>=', Carbon::now()->subHours(24))->count(),
            'success' => AiUsageLog::where('created_at', '>=', Carbon::now()->subHours(24))
                ->whereIn('status', ['success', 'fallback'])->count(),
            'failed' => AiUsageLog::where('created_at', '>=', Carbon::now()->subHours(24))
                ->where('status', 'failed')->count(),
            'total_tokens' => AiUsageLog::where('created_at', '>=', Carbon::now()->subHours(24))
                ->sum('total_tokens'),
            'total_cost' => AiUsageLog::where('created_at', '>=', Carbon::now()->subHours(24))
                ->sum('estimated_cost'),
            'fallback_count' => AiUsageLog::where('created_at', '>=', Carbon::now()->subHours(24))
                ->where('status', 'fallback')->count(),
        ];

        // Ambil alias yang baru dipelajari (auto dari AI + manual dari user)
        $recentAliases = AssetAlias::with(['asset', 'employee'])
            ->latest()
            ->take(20)
            ->get();

        return view('ai-providers.index', compact(
            'providers', 'recentLogs', 'stats24h', 'recentAliases'
        ));
    }

    /**
     * Simpan provider baru
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'provider' => 'required|in:groq,openai,anthropic,ollama',
            'api_key' => 'required|string',
            'model' => 'required|string',
            'api_base_url' => 'nullable|url',
            'priority_order' => 'required|integer|min:1|max:10',
            'max_monthly_tokens' => 'nullable|integer|min:0',
            'requests_per_minute' => 'required|integer|min:1|max:10000',
            'notes' => 'nullable|string',
        ]);

        // Enkripsi API key
        $validated['api_key'] = Crypt::encryptString($validated['api_key']);

        AiProvider::create($validated);

        return redirect()->route('ai-providers.index')
            ->with('success', "✅ AI Provider '{$validated['name']}' berhasil ditambahkan!");
    }

    /**
     * Update provider
     */
    public function update(Request $request, $id)
    {
        $provider = AiProvider::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'provider' => 'required|in:groq,openai,anthropic,ollama',
            'api_key' => 'nullable|string',
            'model' => 'required|string',
            'api_base_url' => 'nullable|url',
            'priority_order' => 'required|integer|min:1|max:10',
            'is_active' => 'boolean',
            'max_monthly_tokens' => 'nullable|integer|min:0',
            'requests_per_minute' => 'required|integer|min:1|max:10000',
            'notes' => 'nullable|string',
        ]);

        // Enkripsi jika API key diisi
        if (!empty($validated['api_key'])) {
            $validated['api_key'] = Crypt::encryptString($validated['api_key']);
        } else {
            unset($validated['api_key']);
        }

        // Reset health status jika diaktifkan kembali
        if (isset($validated['is_active']) && $validated['is_active'] && !$provider->is_active) {
            $validated['health_status'] = 'untested';
        }

        $provider->update($validated);

        return redirect()->route('ai-providers.index')
            ->with('success', "✅ Provider '{$provider->name}' berhasil diperbarui!");
    }

    /**
     * Hapus provider
     */
    public function destroy($id)
    {
        $provider = AiProvider::findOrFail($id);
        $name = $provider->name;

        // Hapus juga usage logs
        $provider->usageLogs()->delete();
        $provider->delete();

        return redirect()->route('ai-providers.index')
            ->with('success', "🗑️ Provider '{$name}' berhasil dihapus!");
    }

    /**
     * Test koneksi ke provider
     */
    public function testConnection($id)
    {
        $result = $this->gateway->testProvider($id);

        if ($result['success']) {
            return redirect()->route('ai-providers.index')
                ->with('success', $result['message']);
        }

        return redirect()->route('ai-providers.index')
            ->with('error', "❌ Gagal: {$result['error']}");
    }

    /**
     * Test semua provider
     */
    public function testAllConnections()
    {
        $providers = AiProvider::where('is_active', true)->orderBy('priority_order')->get();
        $results = [];

        foreach ($providers as $provider) {
            $result = $this->gateway->testProvider($provider->id);
            $results[] = [
                'name' => $provider->name,
                'success' => $result['success'],
                'message' => $result['success'] ? $result['message'] : $result['error'],
            ];
        }

        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $totalCount = count($results);

        return redirect()->route('ai-providers.index')
            ->with('info', "🔍 Hasil test {$successCount}/{$totalCount} provider:\n" .
                collect($results)->map(fn($r) => ($r['success'] ? '✅' : '❌') . " {$r['name']}: {$r['message']}")->implode("\n"));
    }

    /**
     * Reset counter bulanan
     */
    public function resetMonthlyQuota()
    {
        AiProvider::query()->update([
            'current_month_tokens' => 0,
            'month_reset_at' => now(),
        ]);

        return redirect()->route('ai-providers.index')
            ->with('success', "🔄 Quota bulanan semua provider telah di-reset!");
    }

    /**
     * Hapus log pemakaian
     */
    public function cleanUsageLogs()
    {
        $days = request('days', 30);
        $deleted = AiUsageLog::where('created_at', '<', Carbon::now()->subDays($days))->delete();

        return redirect()->route('ai-providers.index')
            ->with('success', "🧹 {$deleted} log pemakaian lebih dari {$days} hari berhasil dihapus.");
    }

    /**
     * Update priority order (drag & drop)
     */
    public function updatePriority(Request $request)
    {
        $request->validate([
            'priorities' => 'required|array',
            'priorities.*.id' => 'required|exists:ai_providers,id',
            'priorities.*.priority' => 'required|integer|min:1|max:10',
        ]);

        foreach ($request->priorities as $item) {
            AiProvider::where('id', $item['id'])->update(['priority_order' => $item['priority']]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * API: Analisa laporan real-time saat user mengetik
     * Dipanggil dari JavaScript di report-manager saat input action_taken
     */
    public function analyzeReport(Request $request)
    {
        $request->validate([
            'raw_text' => 'required|string|min:3',
            'employee_id' => 'required|exists:employees,id',
            'shift' => 'required|string',
            'report_date' => 'required|date',
        ]);

        $employee = \App\Models\Employee::find($request->employee_id);
        if (!$employee) {
            return response()->json([
                'success' => false,
                'error' => 'Teknisi tidak ditemukan',
            ]);
        }

        // Parse teks sederhana
        $lines = array_filter(explode("\n", $request->raw_text));
        $parsedItems = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $status = 'done';
            if (preg_match('/\b(continue|lanjut|belum)\b/i', $line)) $status = 'continue';
            elseif (preg_match('/\b(pending|tunda)\b/i', $line)) $status = 'pending';
            $parsedItems[] = ['action' => $line, 'status' => $status];
        }

        try {
            $this->gateway->withContext($employee);
            $result = $this->gateway->analyzeReport(
                $request->raw_text,
                $parsedItems,
                $employee,
                $request->shift,
                Carbon::parse($request->report_date)
            );
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'items' => $parsedItems,
                'unknown_assets' => [],
                'warnings' => ["Error: {$e->getMessage()}"],
                'new_aliases' => [],
                'provider_used' => null,
                'fallback_chain' => [],
            ]);
        }
    }

    /**
     * Konfirmasi alias yang dipelajari AI
     */
    public function confirmAlias($id)
    {
        $alias = AssetAlias::findOrFail($id);
        $alias->update([
            'confirmed_by_admin' => true,
            'is_rejected' => false,
            'confirmed_at' => now(),
            'confirmed_by_employee_id' => auth()->id(),
        ]);

        return redirect()->back()->with('success', "✅ Alias '{$alias->alias}' dikonfirmasi! AI akan lebih yakin menggunakan mapping ini.");
    }

    /**
     * Reject alias yang dipelajari AI (false positive)
     */
    public function rejectAlias(Request $request, $id)
    {
        $alias = AssetAlias::findOrFail($id);
        $alias->update([
            'confirmed_by_admin' => false,
            'is_rejected' => true,
            'confirmed_at' => now(),
            'confirmed_by_employee_id' => auth()->id(),
            'rejection_reason' => $request->reason ?? 'Tidak sesuai menurut admin',
        ]);

        return redirect()->back()->with('success', "❌ Alias '{$alias->alias}' ditolak. AI tidak akan menggunakannya lagi.");
    }
}
