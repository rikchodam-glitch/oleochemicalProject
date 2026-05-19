<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('nik')->unique();
        $table->string('name');
        $table->string('department'); // Contoh: Produksi, Maintenance
        $table->enum('shift', ['1', '2', '3', 'reguler']);
        $table->string('phone_number')->unique(); // Untuk pencocokan Bot
        $table->string('telegram_id')->nullable(); // Disimpan otomatis oleh Bot nanti
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
