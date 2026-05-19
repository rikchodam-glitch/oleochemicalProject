<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_blacklist', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_chat_id')->unique();
            $table->string('telegram_username')->nullable();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('blocked_by_employee_id')->nullable();
            $table->timestamps();

            $table->foreign('blocked_by_employee_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_blacklist');
    }
};
