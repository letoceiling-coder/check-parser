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
        Schema::table('checks', function (Blueprint $table) {
            // Хеш файла для проверки идентичных файлов
            $table->string('file_hash', 64)->nullable()->after('file_size')->index();
            
            // Идентификатор операции из OCR (номер транзакции банка)
            $table->string('operation_id', 100)->nullable()->after('file_hash')->index();
            
            // Уникальный ключ: комбинация суммы + даты + времени
            $table->string('unique_key', 100)->nullable()->after('operation_id')->index();
            
            // Флаг дубликата
            $table->boolean('is_duplicate')->default(false)->after('unique_key');
            
            // ID оригинального чека (если это дубликат)
            $table->foreignId('original_check_id')->nullable()->after('is_duplicate')
                ->constrained('checks')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->dropForeign(['original_check_id']);
            $table->dropColumn([
                'file_hash',
                'operation_id', 
                'unique_key',
                'is_duplicate',
                'original_check_id'
            ]);
        });
    }
};
