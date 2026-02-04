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
        Schema::create('admin_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_bot_id')->constrained()->onDelete('cascade');
            $table->foreignId('bot_user_id')->constrained()->onDelete('cascade');
            
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_comment')->nullable();
            
            $table->timestamps();
            
            // Один пользователь - один активный запрос
            $table->index(['bot_user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_requests');
    }
};
