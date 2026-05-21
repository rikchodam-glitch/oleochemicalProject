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
        Schema::table('asset_aliases', function (Blueprint $table) {
            $table->boolean('confirmed_by_admin')->default(false)->after('auto_generated');
            $table->boolean('is_rejected')->default(false)->after('confirmed_by_admin');
            $table->timestamp('confirmed_at')->nullable()->after('is_rejected');
            $table->foreignId('confirmed_by_employee_id')->nullable()->constrained('employees')->after('confirmed_at');
            $table->text('rejection_reason')->nullable()->after('confirmed_by_employee_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asset_aliases', function (Blueprint $table) {
            $table->dropColumn([
                'confirmed_by_admin',
                'is_rejected',
                'confirmed_at',
                'confirmed_by_employee_id',
                'rejection_reason',
            ]);
        });
    }
};
