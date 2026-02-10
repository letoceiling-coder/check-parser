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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_bot_id')->constrained()->onDelete('cascade');
            $table->foreignId('raffle_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('bot_user_id')->constrained('bot_users')->onDelete('cascade');
            $table->foreignId('check_id')->nullable()->constrained()->onDelete('set null');
            
            // Статус: reserved, review, sold, rejected, expired
            $table->enum('status', ['reserved', 'review', 'sold', 'rejected', 'expired'])->default('reserved');
            
            // Бронирование
            $table->timestamp('reserved_until')->nullable(); // Время истечения брони (30 мин)
            
            // Заказ
            $table->integer('quantity'); // Количество билетов
            $table->decimal('amount', 15, 2); // Сумма к оплате (quantity * slot_price)
            
            // Выданные билеты (заполняется при одобрении)
            $table->json('ticket_numbers')->nullable(); // [55, 56, 57]
            
            // Проверка
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            
            // Примечания
            $table->text('reject_reason')->nullable();
            $table->text('admin_notes')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index(['telegram_bot_id', 'status']);
            $table->index('reserved_until');
            $table->index('bot_user_id');
            $table->index('raffle_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
