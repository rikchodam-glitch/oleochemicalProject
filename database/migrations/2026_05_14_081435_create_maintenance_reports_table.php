<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_reports', function (Blueprint $table) {
            $table->id();

            // Relasi ke tabel assets dan employees
            $table->foreignId('asset_id')->constrained()->onDelete('cascade');
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');

            // Data Laporan
            $table->text('raw_text');
            $table->string('action_taken')->nullable();
            $table->enum('status', ['done', 'continue', 'pending'])->default('done');

            // Waktu & Shift
            $table->date('report_date');
            $table->enum('shift', ['1', '2', '3', 'reguler']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_reports');
    }
};
