<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_reports', function (Blueprint $table) {
            $table->decimal('ai_confidence', 5, 2)->nullable()->after('source');
            $table->boolean('ai_suggested')->default(false)->after('ai_confidence');
            $table->boolean('needs_admin_review')->default(false)->after('ai_suggested');
            $table->string('ai_provider_used')->nullable()->after('needs_admin_review');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_reports', function (Blueprint $table) {
            $table->dropColumn(['ai_confidence', 'ai_suggested', 'needs_admin_review', 'ai_provider_used']);
        });
    }
};
