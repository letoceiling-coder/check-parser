<?php

namespace App\Console\Commands;

use App\Models\Raffle;
use App\Models\Ticket;
use App\Models\Order;
use Illuminate\Console\Command;

class DiagnoseRaffleCommand extends Command
{
    protected $signature = 'raffle:diagnose {raffle_id=1}';
    protected $description = 'Диагностика проблем с розыгрышем и билетами';

    public function handle(): int
    {
        $raffleId = $this->argument('raffle_id');
        $raffle = Raffle::find($raffleId);
        
        if (!$raffle) {
            $this->error("Розыгрыш #{$raffleId} не найден");
            return 1;
        }
        
        $this->info("=== Розыгрыш #{$raffle->id} ===");
        $this->line("Название: {$raffle->name}");
        $this->line("Статус: {$raffle->status}");
        $this->line("Всего мест: {$raffle->total_slots}");
        $this->line("Выдано билетов: {$raffle->tickets_issued}");
        $this->line("Доступно: " . ($raffle->total_slots - $raffle->tickets_issued));
        $this->newLine();
        
        // Статистика билетов
        $totalTickets = $raffle->tickets()->count();
        $freeTickets = $raffle->tickets()->whereNull('bot_user_id')->whereNull('order_id')->count();
        $reservedTickets = $raffle->tickets()->whereNotNull('order_id')->whereNull('bot_user_id')->count();
        $soldTickets = $raffle->tickets()->whereNotNull('bot_user_id')->count();
        
        $this->info("=== Билеты в БД ===");
        $this->line("Всего создано: {$totalTickets}");
        $this->line("Свободных (NULL/NULL): {$freeTickets}");
        $this->line("Забронировано (order_id/NULL user): {$reservedTickets}");
        $this->line("Продано (user_id SET): {$soldTickets}");
        $this->newLine();
        
        // Проблемы
        $problems = [];
        
        if ($totalTickets != $raffle->total_slots) {
            $problems[] = "Кол-во билетов в БД ($totalTickets) != total_slots ({$raffle->total_slots})";
        }
        
        if ($freeTickets == 0 && $raffle->tickets_issued < $raffle->total_slots) {
            $problems[] = "Нет свободных билетов, но tickets_issued показывает что есть места";
        }
        
        // Проверка просроченных броней
        $expiredOrders = Order::where('raffle_id', $raffleId)
            ->where('status', Order::STATUS_RESERVED)
            ->where('reserved_until', '<', now())
            ->count();
        
        if ($expiredOrders > 0) {
            $problems[] = "Найдено {$expiredOrders} просроченных броней (нужно очистить)";
        }
        
        if (!empty($problems)) {
            $this->error("=== Обнаружены проблемы ===");
            foreach ($problems as $problem) {
                $this->line("❌ {$problem}");
            }
            $this->newLine();
            
            if ($this->confirm('Исправить проблемы автоматически?')) {
                $this->fixProblems($raffle);
            }
        } else {
            $this->info("✅ Проблем не обнаружено");
        }
        
        return 0;
    }
    
    private function fixProblems(Raffle $raffle): void
    {
        $this->info("Исправление проблем...");
        
        // 1. Очистка просроченных броней
        $expired = Order::where('raffle_id', $raffle->id)
            ->where('status', Order::STATUS_RESERVED)
            ->where('reserved_until', '<', now())
            ->get();
        
        if ($expired->count() > 0) {
            $this->line("Очистка {$expired->count()} просроченных броней...");
            foreach ($expired as $order) {
                $order->cancelReservation('Просрочено (ручная очистка)');
            }
            $this->info("✅ Просроченные брони очищены");
        }
        
        // 2. Проверка и пересоздание билетов если нужно
        $totalTickets = $raffle->tickets()->count();
        if ($totalTickets < $raffle->total_slots) {
            $missing = $raffle->total_slots - $totalTickets;
            $this->line("Создание {$missing} недостающих билетов...");
            
            $lastNumber = $raffle->tickets()->max('number') ?? 0;
            for ($i = 1; $i <= $missing; $i++) {
                Ticket::create([
                    'telegram_bot_id' => $raffle->telegram_bot_id,
                    'raffle_id' => $raffle->id,
                    'number' => $lastNumber + $i,
                ]);
            }
            $this->info("✅ Создано {$missing} билетов");
        }
        
        // 3. Пересчёт tickets_issued
        $actualIssued = $raffle->tickets()->whereNotNull('bot_user_id')->count();
        if ($raffle->tickets_issued != $actualIssued) {
            $this->line("Корректировка tickets_issued: {$raffle->tickets_issued} -> {$actualIssued}");
            $raffle->tickets_issued = $actualIssued;
            $raffle->save();
            $this->info("✅ tickets_issued обновлён");
        }
        
        $this->newLine();
        $this->info("🎉 Исправление завершено!");
    }
}
