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
            // Связь с пользователем бота
            $table->foreignId('bot_user_id')->nullable()->after('telegram_bot_id')
                ->constrained()->onDelete('set null');
            
            // Статус проверки администратором
            $table->enum('review_status', ['pending', 'approved', 'rejected'])
                ->default('pending')->after('status');
            
            // Кто проверил
            $table->foreignId('reviewed_by')->nullable()->after('review_status')
                ->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            
            // Количество выданных номерков
            $table->integer('tickets_count')->nullable()->after('reviewed_at');
            
            // Отредактированная сумма (если админ изменил)
            $table->decimal('admin_edited_amount', 10, 2)->nullable()->after('tickets_count');
            
            // Индекс для быстрого поиска
            $table->index(['telegram_bot_id', 'review_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->dropForeign(['bot_user_id']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn([
                'bot_user_id',
                'review_status',
                'reviewed_by',
                'reviewed_at',
                'tickets_count',
                'admin_edited_amount',
            ]);
        });
    }
};
