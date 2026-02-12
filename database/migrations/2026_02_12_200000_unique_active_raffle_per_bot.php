<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Гарантия "только один активный розыгрыш на бота":
 * - PostgreSQL: partial unique index (telegram_bot_id) WHERE status = 'active'
 * - MySQL/SQLite: нет partial index; оставляем только одного активного на бота (данные)
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Только один активный розыгрыш на одного бота
            DB::statement(
                "CREATE UNIQUE INDEX raffles_one_active_per_bot ON raffles (telegram_bot_id) WHERE status = 'active'"
            );
            return;
        }

        // MySQL/SQLite: убедиться, что в данных не более одного active на бота (повтор логики fix_multiple_active_raffles)
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
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS raffles_one_active_per_bot');
        }
    }
};
