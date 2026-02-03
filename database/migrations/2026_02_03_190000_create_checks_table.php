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
        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_bot_id')->nullable()->constrained()->onDelete('set null');
            $table->bigInteger('chat_id');
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            
            // Файл
            $table->string('file_path')->nullable();
            $table->string('file_type')->default('image'); // image, pdf
            $table->integer('file_size')->nullable();
            
            // Распознанные данные
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 10)->default('RUB');
            $table->datetime('check_date')->nullable();
            
            // OCR информация для статистики
            $table->string('ocr_method')->nullable(); // tesseract, ocr_space, remote_tesseract, google_vision
            $table->text('raw_text')->nullable(); // Распознанный текст для анализа
            $table->integer('text_length')->nullable();
            $table->float('readable_ratio')->nullable();
            
            // Статус обработки
            $table->enum('status', ['success', 'partial', 'failed'])->default('failed');
            $table->boolean('amount_found')->default(false);
            $table->boolean('date_found')->default(false);
            
            // Для ручной корректировки
            $table->decimal('corrected_amount', 15, 2)->nullable();
            $table->datetime('corrected_date')->nullable();
            $table->text('admin_notes')->nullable();
            
            $table->timestamps();
            
            // Индексы для быстрого поиска
            $table->index('chat_id');
            $table->index('status');
            $table->index('ocr_method');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checks');
    }
};
