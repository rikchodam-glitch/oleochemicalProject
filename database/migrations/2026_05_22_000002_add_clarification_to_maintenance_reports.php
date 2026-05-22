<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_reports', function (Blueprint $table) {
            $table->string('clarification_status')->nullable()->after('ai_confidence')
                ->comment('null, waiting, resolved, rejected, unidentified');
            $table->string('clarification_session_id')->nullable()->after('clarification_status')
                ->comment('ID sesi klarifikasi dari Telegram');
            $table->text('original_raw_text')->nullable()->after('clarification_session_id')
                ->comment('Teks asli dari user sebelum dikoreksi AI');
            $table->boolean('is_from_telegram')->default(false)->after('original_raw_text')
                ->comment('Apakah laporan berasal dari Telegram bot');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_reports', function (Blueprint $table) {
            $table->dropColumn([
                'clarification_status',
                'clarification_session_id',
                'original_raw_text',
                'is_from_telegram',
            ]);
        });
    }
};
