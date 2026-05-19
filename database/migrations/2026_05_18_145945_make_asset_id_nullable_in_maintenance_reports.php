<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Peringatan: mengubah ke NOT NULL bisa gagal jika ada data dengan asset_id null
        Schema::table('maintenance_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_id')->nullable(false)->change();
        });
    }
};
