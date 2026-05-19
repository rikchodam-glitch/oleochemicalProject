<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramBlacklist extends Model
{
    protected $guarded = [];
    protected $table = 'telegram_blacklist';

    public function blockedBy()
    {
        return $this->belongsTo(Employee::class, 'blocked_by_employee_id');
    }
}
