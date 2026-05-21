<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->onDelete('cascade');
            $table->string('alias');                          // "Pompa 1", "Comp A"
            $table->unsignedBigInteger('employee_id')->nullable(); // NULL = global alias
            $table->decimal('confidence_score', 5, 2)->default(0.00);
            $table->integer('usage_count')->default(1);
            $table->boolean('auto_generated')->default(false); // TRUE jika dari AI
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->unique(['alias', 'employee_id'], 'alias_per_employee_unique');
            $table->index('alias');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_aliases');
    }
};
