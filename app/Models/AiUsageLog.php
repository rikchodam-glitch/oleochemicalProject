<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'had_fallback' => 'boolean',
        'estimated_cost' => 'decimal:6',
    ];

    public function provider()
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function telegramBotLog()
    {
        return $this->belongsTo(TelegramBotLog::class);
    }

    public function maintenanceReport()
    {
        return $this->belongsTo(MaintenanceReport::class);
    }
}
