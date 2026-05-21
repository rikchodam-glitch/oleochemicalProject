<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class AiProvider extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'minute_reset_at' => 'datetime',
        'month_reset_at' => 'datetime',
        'last_health_check_at' => 'datetime',
    ];

    /**
     * Decrypt API key when accessing
     */
    public function getDecryptedApiKey(): string
    {
        try {
            return Crypt::decryptString($this->api_key);
        } catch (\Throwable $e) {
            return $this->api_key; // fallback jika belum dienkripsi
        }
    }

    /**
     * Relasi ke usage logs
     */
    public function usageLogs()
    {
        return $this->hasMany(AiUsageLog::class);
    }

    /**
     * Hitung sisa token bulanan
     */
    public function getRemainingMonthlyTokens(): ?int
    {
        if (!$this->max_monthly_tokens) return null;
        return max(0, $this->max_monthly_tokens - $this->current_month_tokens);
    }

    /**
     * Dapatkan persentase sisa token
     */
    public function getTokenUsagePercentage(): ?float
    {
        if (!$this->max_monthly_tokens || $this->max_monthly_tokens <= 0) return null;
        return round(($this->current_month_tokens / $this->max_monthly_tokens) * 100, 1);
    }

    /**
     * Status label untuk UI
     */
    public function getStatusLabelAttribute(): string
    {
        if (!$this->is_active) return 'nonaktif';
        if ($this->health_status === 'unhealthy') return 'error';
        
        $sisa = $this->getRemainingMonthlyTokens();
        if ($sisa === null) return 'aktif';
        
        $persen = $this->getTokenUsagePercentage();
        if ($persen >= 100) return 'habis';
        if ($persen >= 90) return 'kritis';
        if ($persen >= 70) return 'menipis';
        
        return 'aktif';
    }

    /**
     * Warna status untuk UI
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status_label) {
            'aktif' => 'green',
            'menipis' => 'yellow',
            'kritis' => 'orange',
            'habis' => 'red',
            'error' => 'red',
            'nonaktif' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Scope: hanya provider yang available (aktif, sehat, dan punya quota)
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->where(function($q) {
                $q->whereNull('max_monthly_tokens')
                  ->orWhereColumn('current_month_tokens', '<', 'max_monthly_tokens');
            })
            ->orderBy('priority_order');
    }
}
