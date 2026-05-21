<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');                          // "Groq Primary", "Groq Backup 1"
            $table->string('provider');                      // 'groq', 'openai', 'anthropic', 'ollama'
            $table->string('model')->default('llama-70b-8192'); // model AI
            $table->text('api_key');                        // Akan dienkripsi
            $table->string('api_base_url')->nullable();     // Custom endpoint
            $table->integer('priority_order')->default(1);  // Urutan prioritas 1,2,3,4
            $table->boolean('is_active')->default(true);

            // Tracking pemakaian
            $table->bigInteger('total_requests')->default(0);
            $table->bigInteger('total_tokens_used')->default(0);
            $table->timestamp('last_used_at')->nullable();

            // Rate limiting
            $table->integer('requests_per_minute')->default(30);
            $table->integer('requests_this_minute')->default(0);
            $table->timestamp('minute_reset_at')->nullable();

            // Quota management
            $table->bigInteger('max_monthly_tokens')->nullable();
            $table->bigInteger('current_month_tokens')->default(0);
            $table->timestamp('month_reset_at')->nullable();

            // Health check
            $table->string('health_status')->default('untested'); // 'healthy', 'unhealthy', 'untested'
            $table->timestamp('last_health_check_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
