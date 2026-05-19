<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    // Mengizinkan semua kolom untuk diisi secara otomatis (Mass Assignment)
    protected $guarded = [];

    // Relasi ke tabel Companies (PT)
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // Relasi ke tabel Departments (Departemen)
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    // Relasi ke tabel Areas (Area)
    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    // Relasi ke tabel Sub Areas (Sub Area)
    public function subArea()
    {
        return $this->belongsTo(SubArea::class);
    }
}
