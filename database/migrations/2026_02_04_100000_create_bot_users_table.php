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
        Schema::create('bot_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_bot_id')->constrained()->onDelete('cascade');
            $table->bigInteger('telegram_user_id')->index();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->enum('role', ['user', 'admin'])->default('user');
            
            // Зашифрованные персональные данные
            $table->text('fio_encrypted')->nullable();
            $table->text('phone_encrypted')->nullable();
            $table->text('inn_encrypted')->nullable();
            
            // FSM состояние
            $table->string('fsm_state', 50)->default('IDLE');
            $table->json('fsm_data')->nullable(); // Временные данные для FSM
            $table->bigInteger('last_bot_message_id')->nullable(); // Для editMessageText
            
            $table->boolean('is_blocked')->default(false);
            $table->boolean('notify_on_slots_available')->default(false); // Уведомить когда места появятся
            
            $table->timestamps();
            
            // Уникальный пользователь для каждого бота
            $table->unique(['telegram_bot_id', 'telegram_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_users');
    }
};
