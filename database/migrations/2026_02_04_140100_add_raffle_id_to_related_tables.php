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
        // Добавляем raffle_id в таблицу checks
        Schema::table('checks', function (Blueprint $table) {
            $table->foreignId('raffle_id')->nullable()->after('telegram_bot_id')
                ->constrained('raffles')->onDelete('set null');
        });

        // Добавляем raffle_id в таблицу tickets
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('raffle_id')->nullable()->after('telegram_bot_id')
                ->constrained('raffles')->onDelete('set null');
        });

        // Добавляем current_raffle_id в bot_settings
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->foreignId('current_raffle_id')->nullable()->after('telegram_bot_id')
                ->constrained('raffles')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table) {
            $table->dropForeign(['raffle_id']);
            $table->dropColumn('raffle_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['raffle_id']);
            $table->dropColumn('raffle_id');
        });

        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropForeign(['current_raffle_id']);
            $table->dropColumn('current_raffle_id');
        });
    }
};
