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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_bot_id')->constrained()->onDelete('cascade');
            $table->integer('number'); // Номер билета (1-500)
            
            $table->foreignId('bot_user_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('check_id')->nullable()->constrained()->onDelete('set null');
            
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            
            // Уникальный номер для каждого бота
            $table->unique(['telegram_bot_id', 'number']);
            
            // Индекс для быстрого поиска свободных
            $table->index(['telegram_bot_id', 'bot_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
