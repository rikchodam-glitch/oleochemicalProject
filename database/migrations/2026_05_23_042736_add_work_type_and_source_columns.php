<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah kolom source ke asset_aliases (untuk tracking asal alias)
        if (!Schema::hasColumn('asset_aliases', 'source')) {
            Schema::table('asset_aliases', function (Blueprint $table) {
                $table->string('source', 50)->nullable()->after('auto_generated')
                    ->comment('auto_ai, clarification, manual, user_correction');
            });
        }

        // Tambah kolom work_type ke maintenance_reports
        if (!Schema::hasColumn('maintenance_reports', 'work_type')) {
            Schema::table('maintenance_reports', function (Blueprint $table) {
                $table->string('work_type', 50)->nullable()->after('status')
                    ->comment('equipment, area, new_equipment, skipped, unknown');
            });
        }

        // Tambah kolom duration_hours ke maintenance_reports
        if (!Schema::hasColumn('maintenance_reports', 'duration_hours')) {
            Schema::table('maintenance_reports', function (Blueprint $table) {
                $table->decimal('duration_hours', 5, 1)->nullable()->after('work_type')
                    ->comment('Durasi pengerjaan dalam jam, contoh: 1.5, 2.0, 0.5');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('asset_aliases', 'source')) {
            Schema::table('asset_aliases', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }

        if (Schema::hasColumn('maintenance_reports', 'work_type')) {
            Schema::table('maintenance_reports', function (Blueprint $table) {
                $table->dropColumn('work_type');
            });
        }

        if (Schema::hasColumn('maintenance_reports', 'duration_hours')) {
            Schema::table('maintenance_reports', function (Blueprint $table) {
                $table->dropColumn('duration_hours');
            });
        }
    }
};
