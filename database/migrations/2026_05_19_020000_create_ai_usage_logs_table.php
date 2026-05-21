<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('telegram_bot_log_id')->nullable();
            $table->unsignedBigInteger('maintenance_report_id')->nullable();
            
            // Request info
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->integer('processing_time_ms')->default(0);
            $table->string('model_used')->nullable();
            
            // Status
            $table->enum('status', ['success', 'failed', 'fallback'])->default('success');
            $table->text('error_message')->nullable();
            $table->boolean('had_fallback')->default(false);
            $table->string('fallback_chain')->nullable();
            
            // Biaya (estimasi)
            $table->decimal('estimated_cost', 10, 6)->default(0);
            
            $table->timestamps();
            
            $table->foreign('telegram_bot_log_id')->references('id')->on('telegram_bot_logs')->nullOnDelete();
            $table->foreign('maintenance_report_id')->references('id')->on('maintenance_reports')->nullOnDelete();
            
            $table->index('created_at');
            $table->index(['ai_provider_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
