<?php

namespace App\Console\Commands;

use App\Models\TelegramBot;
use Illuminate\Console\Command;

class InitializeRaffleTicketsCommand extends Command
{
    protected $signature = 'raffle:init-tickets {bot_id=1}';
    protected $description = 'Инициализировать билеты для розыгрыша';

    public function handle(): int
    {
        $botId = $this->argument('bot_id');
        $bot = TelegramBot::find($botId);
        
        if (!$bot) {
            $this->error("Бот #{$botId} не найден");
            return 1;
        }
        
        $this->info("Инициализация билетов для бота: {$bot->name}");
        
        try {
            $bot->initializeTickets();
            $this->info("✅ Билеты успешно инициализированы");
            return 0;
        } catch (\Exception $e) {
            $this->error("Ошибка: " . $e->getMessage());
            return 1;
        }
    }
}
