<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_alias_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_alias_id')->nullable()->constrained()->nullOnDelete();
            $table->string('alias');                           // Teks yang dipelajari
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('asset_code')->nullable();          // tech_ident_no
            $table->string('asset_description')->nullable();
            
            // Konteks saat mapping terjadi
            $table->text('original_text')->nullable();         // Teks asli user
            $table->text('keywords_used')->nullable();         // Keyword yang diekstrak (JSON)
            $table->text('area_detected')->nullable();         // Area yang terdeteksi (RG1, BD1, dll)
            $table->text('area_asset')->nullable();            // Area dari asset yang dipilih
            
            // Opsi yang dipertimbangkan AI
            $table->text('ai_possible_assets')->nullable();    // JSON array opsi dari AI
            $table->text('ai_reasoning')->nullable();          // Penjelasan AI kenapa pilih ini
            
            // Hasil
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->boolean('area_match')->default(false);     // Apakah area cocok?
            $table->string('action_taken');                    // 'user_selected', 'ai_auto_resolve', 'admin_confirmed'
            
            // Metadata
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('telegram_username')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_alias_audit_logs');
    }
};
