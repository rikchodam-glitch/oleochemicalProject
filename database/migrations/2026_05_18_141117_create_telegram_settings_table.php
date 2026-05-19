<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Seed default settings
        DB::table('telegram_settings')->insert([
            ['key' => 'bot_token', 'value' => '8791074827:AAHxRE_943bhOL7ydXigW6Yufqhjc2DIeYM', 'description' => 'Token Bot Telegram', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'webhook_url', 'value' => '', 'description' => 'URL Webhook untuk Bot Telegram', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'bot_status', 'value' => 'active', 'description' => 'Status Bot: active/inactive/maintenance', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'auto_approve', 'value' => 'false', 'description' => 'Auto-approve registrasi teknisi baru', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'max_items_per_report', 'value' => '20', 'description' => 'Maksimal item per laporan', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'notification_new_report', 'value' => 'true', 'description' => 'Kirim notifikasi web ke admin', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_settings');
    }
};
