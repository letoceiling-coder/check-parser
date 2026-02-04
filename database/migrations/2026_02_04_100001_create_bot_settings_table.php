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
        Schema::create('bot_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_bot_id')->unique()->constrained()->onDelete('cascade');
            
            // Настройки розыгрыша
            $table->integer('total_slots')->default(500); // Общее количество мест
            $table->decimal('slot_price', 10, 2)->default(10000.00); // Стоимость одного места
            $table->enum('slots_mode', ['sequential', 'random'])->default('sequential'); // Режим выдачи
            $table->boolean('is_active')->default(true); // Принимаются ли платежи
            
            // QR-код и платёж
            $table->string('qr_image_path')->nullable()->default('bot-assets/default-qr.jpg');
            $table->string('payment_description')->default('Оплата наклейки');
            
            // Тексты сообщений бота (редактируемые)
            $table->text('msg_welcome')->nullable(); // Приветствие
            $table->text('msg_no_slots')->nullable(); // Нет мест
            $table->text('msg_ask_fio')->nullable(); // Запрос ФИО
            $table->text('msg_ask_phone')->nullable(); // Запрос телефона
            $table->text('msg_ask_inn')->nullable(); // Запрос ИНН
            $table->text('msg_confirm_data')->nullable(); // Подтверждение данных
            $table->text('msg_show_qr')->nullable(); // Показ QR
            $table->text('msg_wait_check')->nullable(); // Ожидание чека
            $table->text('msg_check_received')->nullable(); // Чек получен
            $table->text('msg_check_approved')->nullable(); // Чек одобрен
            $table->text('msg_check_rejected')->nullable(); // Чек отклонён
            $table->text('msg_admin_request_sent')->nullable(); // Запрос на админа отправлен
            $table->text('msg_admin_request_approved')->nullable(); // Запрос одобрен
            $table->text('msg_admin_request_rejected')->nullable(); // Запрос отклонён
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_settings');
    }
};
