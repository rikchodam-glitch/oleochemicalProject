<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAliasAuditLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'keywords_used' => 'array',
        'ai_possible_assets' => 'array',
        'area_match' => 'boolean',
        'confidence_score' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    public function assetAlias()
    {
        return $this->belongsTo(AssetAlias::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
