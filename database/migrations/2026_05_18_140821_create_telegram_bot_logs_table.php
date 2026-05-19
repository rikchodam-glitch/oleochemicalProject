<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bot_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->string('telegram_username')->nullable();
            $table->text('incoming_message')->nullable();
            $table->text('response_message')->nullable();
            $table->string('message_type'); // 'text','photo','command','callback_query','error'
            $table->string('parsing_status')->default('pending'); // 'pending','success','failed'
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('maintenance_report_id')->nullable();
            $table->string('bot_command')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('maintenance_report_id')->references('id')->on('maintenance_reports')->nullOnDelete();
            $table->index('telegram_chat_id');
            $table->index('parsing_status');
            $table->index('message_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bot_logs');
    }
};
