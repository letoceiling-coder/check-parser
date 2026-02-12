<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Оставить только один активный розыгрыш на бота (последний по id), остальные — приостановлены.
     */
    public function up(): void
    {
        $botIds = DB::table('raffles')
            ->where('status', 'active')
            ->select('telegram_bot_id')
            ->groupBy('telegram_bot_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('telegram_bot_id');

        foreach ($botIds as $botId) {
            $keepId = DB::table('raffles')
                ->where('telegram_bot_id', $botId)
                ->where('status', 'active')
                ->orderByDesc('id')
                ->value('id');

            if ($keepId) {
                DB::table('raffles')
                    ->where('telegram_bot_id', $botId)
                    ->where('status', 'active')
                    ->where('id', '!=', $keepId)
                    ->update(['status' => 'paused']);
            }
        }
    }

    public function down(): void
    {
        // Не восстанавливаем несколько active — необратимо
    }
};
