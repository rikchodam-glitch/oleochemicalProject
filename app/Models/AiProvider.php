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
        'daily_reset_at' => 'datetime',
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
     * Hitung sisa token harian (TPD)
     */
    public function getRemainingDailyTokens(): ?int
    {
        if (!$this->max_daily_tokens) return null;
        return max(0, $this->max_daily_tokens - $this->current_daily_tokens);
    }

    /**
     * Dapatkan persentase sisa token harian
     */
    public function getDailyTokenUsagePercentage(): ?float
    {
        if (!$this->max_daily_tokens || $this->max_daily_tokens <= 0) return null;
        return round(($this->current_daily_tokens / $this->max_daily_tokens) * 100, 1);
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
     * Cek apakah provider masih punya kuota (daily + monthly)
     */
    public function hasAvailableQuota(): bool
    {
        // Cek daily quota
        if ($this->max_daily_tokens && $this->current_daily_tokens >= $this->max_daily_tokens) {
            return false;
        }
        // Cek monthly quota
        if ($this->max_monthly_tokens && $this->current_month_tokens >= $this->max_monthly_tokens) {
            return false;
        }
        return true;
    }

    /**
     * Status label untuk UI (updated: include daily check)
     */
    public function getStatusLabelAttribute(): string
    {
        if (!$this->is_active) return 'nonaktif';
        if ($this->health_status === 'unhealthy') return 'error';

        // Cek daily dulu (lebih prioritas)
        $sisaDaily = $this->getRemainingDailyTokens();
        if ($sisaDaily !== null && $sisaDaily <= 0) return 'habis';

        $sisaMonth = $this->getRemainingMonthlyTokens();
        if ($sisaMonth === null && $sisaDaily !== null) {
            // Hanya daily yang di-set, monthly unlimited
            $persenDaily = $this->getDailyTokenUsagePercentage();
            if ($persenDaily >= 90) return 'kritis';
            if ($persenDaily >= 70) return 'menipis';
            return 'aktif';
        }
        if ($sisaMonth === null) return 'aktif';

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
     * Scope: hanya provider yang available (aktif, sehat, dan punya quota daily + monthly)
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->where(function($q) {
                $q->whereNull('max_monthly_tokens')
                  ->orWhere('max_monthly_tokens', 0)
                  ->orWhereColumn('current_month_tokens', '<', 'max_monthly_tokens');
            })
            ->where(function($q) {
                $q->whereNull('max_daily_tokens')
                  ->orWhere('max_daily_tokens', 0)
                  ->orWhereColumn('current_daily_tokens', '<', 'max_daily_tokens');
            })
            ->orderBy('priority_order');
    }
}

