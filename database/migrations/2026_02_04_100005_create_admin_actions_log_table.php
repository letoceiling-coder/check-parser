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
        Schema::create('admin_actions_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_bot_id')->constrained()->onDelete('cascade');
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->bigInteger('admin_telegram_id')->nullable(); // Если действие из Telegram
            
            $table->string('action_type', 50); // check_approved, check_rejected, admin_request_approved, etc.
            $table->string('target_type', 50); // check, admin_request, ticket, bot_user
            $table->unsignedBigInteger('target_id');
            
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
            $table->text('comment')->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            
            $table->index(['telegram_bot_id', 'action_type']);
            $table->index(['target_type', 'target_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_actions_log');
    }
};
