<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetAlias extends Model
{
    protected $guarded = [];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'auto_generated' => 'boolean',
        'confirmed_by_admin' => 'boolean',
        'is_rejected' => 'boolean',
        'last_used_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function confirmedBy()
    {
        return $this->belongsTo(Employee::class, 'confirmed_by_employee_id');
    }

    /**
     * Scope: hanya alias yang sudah dikonfirmasi / belum pernah ditolak
     */
    public function scopeApproved($query)
    {
        return $query->where('confirmed_by_admin', true)->where('is_rejected', false);
    }

    /**
     * Scope: alias yang perlu review admin
     */
    public function scopeNeedsReview($query)
    {
        return $query->where('auto_generated', true)
            ->where('confirmed_by_admin', false)
            ->where('is_rejected', false);
    }

    /**
     * Scope: cari alias yang cocok (hanya yang sudah approved)
     */
    public function scopeForEmployee($query, ?int $employeeId)
    {
        return $query->approved()->where(function($q) use ($employeeId) {
            $q->whereNull('employee_id')
              ->orWhere('employee_id', $employeeId);
        });
    }
}
