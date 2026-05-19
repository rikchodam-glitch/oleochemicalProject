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
    Schema::create('assets', function (Blueprint $table) {
        $table->id();
        $table->string('equipment_no')->nullable()->unique();
        $table->text('description')->nullable();
        $table->string('tech_ident_no')->nullable()->index();
        $table->string('object_type')->nullable();

        // Relasi ke Master Data Lokasi
        $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('sub_area_id')->nullable()->constrained()->nullOnDelete();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
