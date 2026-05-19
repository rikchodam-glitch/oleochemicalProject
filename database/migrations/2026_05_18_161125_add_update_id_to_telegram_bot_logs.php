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
        Schema::table('telegram_bot_logs', function (Blueprint $table) {
            $table->bigInteger('telegram_update_id')->nullable()->after('id');
            $table->index('telegram_update_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_bot_logs', function (Blueprint $table) {
            $table->dropColumn('telegram_update_id');
        });
    }
};

