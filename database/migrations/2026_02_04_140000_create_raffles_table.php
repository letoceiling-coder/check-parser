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
        Schema::create('raffles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_bot_id')->constrained()->onDelete('cascade');
            
            // Основная информация
            $table->string('name')->nullable(); // Название розыгрыша (например "Розыгрыш #1")
            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');
            
            // Настройки розыгрыша (копируются из bot_settings при создании)
            $table->integer('total_slots')->default(500);
            $table->decimal('slot_price', 10, 2)->default(10000.00);
            $table->string('slots_mode', 20)->default('sequential');
            
            // Победитель
            $table->foreignId('winner_ticket_id')->nullable()->constrained('tickets')->onDelete('set null');
            $table->foreignId('winner_bot_user_id')->nullable()->constrained('bot_users')->onDelete('set null');
            $table->integer('winner_ticket_number')->nullable(); // Номер выигрышного билета
            
            // Статистика
            $table->integer('total_participants')->default(0); // Количество участников
            $table->integer('tickets_issued')->default(0); // Выдано номерков
            $table->decimal('total_revenue', 12, 2)->default(0); // Общая сумма оплат
            $table->integer('checks_count')->default(0); // Количество чеков
            
            // Даты
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            // Примечания
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index(['telegram_bot_id', 'status']);
            $table->index('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('raffles');
    }
};
