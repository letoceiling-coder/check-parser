<?php

namespace App\Console\Commands;

use App\Models\BotSettings;
use App\Models\Raffle;
use App\Models\TelegramBot;
use App\Models\Ticket;
use Illuminate\Console\Command;

/**
 * Диагностика активного розыгрыша для проверки соответствия данных между админкой и ботом.
 * Запуск на сервере: php artisan raffle:diagnose-active
 * Или для конкретного бота: php artisan raffle:diagnose-active --bot=1
 */
class DiagnoseActiveRaffleCommand extends Command
{
    protected $signature = 'raffle:diagnose-active {--bot= : ID бота (если не указан — все активные боты)}';
    protected $description = 'Показать активный розыгрыш и реальные данные (для сверки с админкой и ботом)';

    public function handle(): int
    {
        $botId = $this->option('bot');
        $bots = $botId
            ? TelegramBot::where('id', (int) $botId)->get()
            : TelegramBot::where('is_active', true)->get();

        if ($bots->isEmpty()) {
            $this->warn('Боты не найдены.');
            return 0;
        }

        foreach ($bots as $bot) {
            $this->diagnoseBot($bot);
        }

        return 0;
    }

    private function diagnoseBot(TelegramBot $bot): void
    {
        $this->newLine();
        $this->info("=== Бот ID: {$bot->id} ===");

        $settings = BotSettings::where('telegram_bot_id', $bot->id)->first();
        $currentRaffleId = $settings?->current_raffle_id;

        $activeRaffle = Raffle::resolveActiveForBot($bot->id);

        if (!$activeRaffle) {
            $this->line('Активный розыгрыш: <comment>нет</comment>');
            $this->line('BotSettings.current_raffle_id: ' . ($currentRaffleId ?? 'null'));
            return;
        }

        $activeRaffle->updateStatistics();
        $activeRaffle->refresh();

        $issuedCount = Ticket::where('raffle_id', $activeRaffle->id)
            ->where(function ($q) {
                $q->whereNotNull('bot_user_id')->orWhereNotNull('order_id');
            })
            ->count();
        $available = max(0, (int) $activeRaffle->total_slots - $issuedCount);

        $this->line("Активный розыгрыш: <info>#{$activeRaffle->id}</info> {$activeRaffle->name}");
        $this->line("Статус: {$activeRaffle->status}");
        $this->line("BotSettings.current_raffle_id: " . ($currentRaffleId ?? 'null') . ($currentRaffleId == $activeRaffle->id ? ' ✓' : ' <comment>не совпадает!</comment>'));
        $this->line('');
        $this->line("Данные из модели (raffle):");
        $this->line("  total_slots: {$activeRaffle->total_slots}");
        $this->line("  tickets_issued (в модели): {$activeRaffle->tickets_issued}");
        $this->line("  total_participants: {$activeRaffle->total_participants}");
        $this->line("  total_revenue: {$activeRaffle->total_revenue}");
        $this->line("  checks_count: {$activeRaffle->checks_count}");
        $this->line('');
        $this->line("Реальный подсчёт по таблице tickets (raffle_id={$activeRaffle->id}):");
        $this->line("  выдано (bot_user_id или order_id): {$issuedCount}");
        $this->line("  доступно мест: {$available}");
        $this->line('');

        $fromSettings = $settings ? $settings->getAvailableSlotsCount() : 0;
        if ($fromSettings !== $available) {
            $this->warn("Расхождение: BotSettings::getAvailableSlotsCount() = {$fromSettings}, реально доступно = {$available}");
        } else {
            $this->line("BotSettings::getAvailableSlotsCount() = {$fromSettings} (совпадает с доступными местами)");
        }
    }
}
