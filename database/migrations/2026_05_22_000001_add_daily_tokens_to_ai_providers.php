<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->bigInteger('max_daily_tokens')->nullable()->after('max_monthly_tokens')
                ->comment('Batas maksimal token per hari (TPD)');
            $table->bigInteger('current_daily_tokens')->default(0)->after('current_month_tokens')
                ->comment('Token terpakai hari ini');
            $table->timestamp('daily_reset_at')->nullable()->after('month_reset_at')
                ->comment('Waktu terakhir reset daily');
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->dropColumn(['max_daily_tokens', 'current_daily_tokens', 'daily_reset_at']);
        });
    }
};
