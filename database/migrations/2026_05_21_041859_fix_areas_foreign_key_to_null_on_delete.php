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
        Schema::table('areas', function (Blueprint $table) {
            // Drop foreign key constraint existing
            $table->dropForeign(['department_id']);

            // Ubah kolom jadi nullable dulu (karena nullOnDelete butuh kolom nullable)
            $table->unsignedBigInteger('department_id')->nullable()->change();

            // Tambah ulang dengan nullOnDelete
            $table->foreign('department_id')
                  ->references('id')
                  ->on('departments')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            // Kembalikan ke cascadeOnDelete
            $table->dropForeign(['department_id']);

            // Kembalikan ke NOT NULL
            $table->unsignedBigInteger('department_id')->nullable(false)->change();

            $table->foreign('department_id')
                  ->references('id')
                  ->on('departments')
                  ->cascadeOnDelete();
        });
    }
};

