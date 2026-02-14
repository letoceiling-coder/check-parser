<?php

namespace App\Console\Commands;

use App\Models\Raffle;
use App\Models\BotSettings;
use Illuminate\Console\Command;

/**
 * Удаление розыгрышей (в т.ч. тестовых).
 * Связанные записи: tickets, orders, checks — raffle_id обнулится по FK (onDelete set null).
 * current_raffle_id в bot_settings тоже обнулится для удаляемых розыгрышей.
 */
class DeleteRafflesCommand extends Command
{
    protected $signature = 'raffles:delete
                            {--id=* : ID розыгрышей для удаления (можно несколько: --id=1 --id=2)}
                            {--all : Удалить все розыгрыши}
                            {--force : Не спрашивать подтверждение}';

    protected $description = 'Удалить указанные или все (тестовые) розыгрыши';

    public function handle(): int
    {
        if ($this->option('all')) {
            $raffles = Raffle::orderBy('id')->get();
        } else {
            $ids = $this->option('id');
            if (empty($ids)) {
                $this->listRaffles();
                $this->line('');
                $this->info('Варианты удаления:');
                $this->line('  php artisan raffles:delete --all              — удалить все розыгрыши');
                $this->line('  php artisan raffles:delete --id=14 --id=15   — удалить по ID');
                return 0;
            }
            $raffles = Raffle::whereIn('id', $ids)->orderBy('id')->get();
            $missing = array_diff($ids, $raffles->pluck('id')->all());
            if (!empty($missing)) {
                $this->warn('Розыгрыши с ID ' . implode(', ', $missing) . ' не найдены.');
            }
        }

        if ($raffles->isEmpty()) {
            $this->info('Нет розыгрышей для удаления.');
            return 0;
        }

        $this->table(
            ['ID', 'Бот', 'Название', 'Статус', 'Мест', 'Выдано'],
            $raffles->map(fn (Raffle $r) => [
                $r->id,
                $r->telegram_bot_id,
                $r->name ?? '—',
                $r->status,
                $r->total_slots,
                $r->tickets_issued,
            ])
        );

        if (!$this->option('force') && !$this->confirm('Удалить эти ' . $raffles->count() . ' розыгрыш(ей)? Связанные номерки/заказы/чеки останутся, у них обнулится raffle_id.', true)) {
            $this->info('Отменено.');
            return 0;
        }

        $count = 0;
        foreach ($raffles as $raffle) {
            // Обнуляем current_raffle_id в настройках бота, если указывал на этот розыгрыш
            BotSettings::where('current_raffle_id', $raffle->id)->update(['current_raffle_id' => null]);
            $raffle->delete();
            $count++;
            $this->line("Удалён розыгрыш #{$raffle->id} ({$raffle->name})");
        }

        $this->info("Готово. Удалено розыгрышей: {$count}.");
        return 0;
    }

    private function listRaffles(): void
    {
        $all = Raffle::orderBy('id')->get();
        if ($all->isEmpty()) {
            $this->info('Розыгрышей в БД нет.');
            return;
        }
        $this->info('Розыгрыши в БД:');
        $this->table(
            ['ID', 'Бот', 'Название', 'Статус', 'Мест', 'Выдано'],
            $all->map(fn (Raffle $r) => [
                $r->id,
                $r->telegram_bot_id,
                $r->name ?? '—',
                $r->status,
                $r->total_slots,
                $r->tickets_issued,
            ])
        );
    }
}
