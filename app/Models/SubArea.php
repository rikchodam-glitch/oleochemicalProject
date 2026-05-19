<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubArea extends Model
{
    protected $guarded = [];

    // Relasi inverse: Sub Area milik satu Area
    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    // Relasi: Satu Sub Area memiliki banyak Aset
    public function assets()
    {
        return $this->hasMany(Asset::class);
    }
}
