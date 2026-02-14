<?php

namespace App\Console\Commands;

use App\Models\Raffle;
use App\Models\Ticket;
use App\Models\Order;
use App\Models\Check;
use App\Models\BotUser;
use Illuminate\Console\Command;

class DiagnoseRaffleCommand extends Command
{
    protected $signature = 'raffle:diagnose {raffle_id?} {--fix : Автоматически исправить проблемы} {--active : Проверить активный розыгрыш}';
    protected $description = 'Диагностика проблем с розыгрышем и билетами';

    public function handle(): int
    {
        $raffleId = $this->argument('raffle_id');
        
        // Если указан --active или не указан raffle_id, используем активный розыгрыш
        if ($this->option('active') || !$raffleId) {
            $bot = \App\Models\TelegramBot::first();
            if (!$bot) {
                $this->error("Бот не найден");
                return 1;
            }
            $raffle = Raffle::resolveActiveForBot($bot->id);
            if (!$raffle) {
                $this->error("Активный розыгрыш не найден");
                return 1;
            }
            $this->info("Используется активный розыгрыш для бота #{$bot->id}");
        } else {
        $raffle = Raffle::find($raffleId);
        }
        
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
        $this->line("С order_id (бронь/проверка/зависшие): {$reservedTickets}");
        $this->line("Продано (user_id SET): {$soldTickets}");
        // Разбивка билетов с order_id по статусу заказа (чтобы понять «498 свободно» при 2 с order_id)
        if ($reservedTickets > 0) {
            $byStatus = [
                'RESERVED' => Ticket::where('raffle_id', $raffle->id)->whereNotNull('order_id')->whereHas('order', fn ($q) => $q->where('status', Order::STATUS_RESERVED))->count(),
                'REVIEW' => Ticket::where('raffle_id', $raffle->id)->whereNotNull('order_id')->whereHas('order', fn ($q) => $q->where('status', Order::STATUS_REVIEW))->count(),
                'EXPIRED/REJECTED' => Ticket::where('raffle_id', $raffle->id)->whereNotNull('order_id')->whereHas('order', fn ($q) => $q->whereIn('status', [Order::STATUS_EXPIRED, Order::STATUS_REJECTED]))->count(),
                'SOLD' => Ticket::where('raffle_id', $raffle->id)->whereNotNull('order_id')->whereNull('bot_user_id')->whereHas('order', fn ($q) => $q->where('status', Order::STATUS_SOLD))->count(),
            ];
            $this->line("  → по заказам: RESERVED={$byStatus['RESERVED']}, REVIEW={$byStatus['REVIEW']}, EXPIRED/REJECTED={$byStatus['EXPIRED/REJECTED']}, SOLD(без user)={$byStatus['SOLD']}");
        }
        $this->newLine();

        // Реальные данные для сравнения
        // Учитываем только реально выданные билеты (с bot_user_id)
        // Билеты с order_id но без bot_user_id - это только бронь, они не считаются выданными
        $actualIssued = Ticket::where('raffle_id', $raffle->id)
            ->whereNotNull('bot_user_id')
            ->count();
        $actualParticipants = BotUser::whereHas('tickets', function ($query) use ($raffle) {
            $query->where('raffle_id', $raffle->id);
        })->count();
        $actualRevenue = Check::where('raffle_id', $raffle->id)
            ->where('review_status', 'approved')
            ->get()
            ->sum(function ($check) {
                return $check->admin_edited_amount ?? $check->corrected_amount ?? $check->amount ?? 0;
            });
        $actualChecksCount = Check::where('raffle_id', $raffle->id)->count();
        
        $this->newLine();
        $this->info("=== Сравнение кэша и реальных данных ===");
        $this->line("Участники: кэш={$raffle->total_participants}, реально={$actualParticipants} " . 
            ($raffle->total_participants == $actualParticipants ? "✅" : "❌"));
        $this->line("Выдано билетов: кэш={$raffle->tickets_issued}, реально={$actualIssued} " . 
            ($raffle->tickets_issued == $actualIssued ? "✅" : "❌"));
        $this->line("Выручка: кэш={$raffle->total_revenue}, реально={$actualRevenue} " . 
            (abs($raffle->total_revenue - $actualRevenue) < 0.01 ? "✅" : "❌"));
        $this->line("Чеков: кэш={$raffle->checks_count}, реально={$actualChecksCount} " . 
            ($raffle->checks_count == $actualChecksCount ? "✅" : "❌"));
        $this->line("Доступно мест: " . ($raffle->total_slots - $actualIssued));
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
        $expiredOrders = Order::where('raffle_id', $raffle->id)
            ->where('status', Order::STATUS_RESERVED)
            ->where('reserved_until', '<', now())
            ->count();

        if ($expiredOrders > 0) {
            $problems[] = "Найдено {$expiredOrders} просроченных броней (нужно очистить)";
        }

        // Билеты «зависшие»: неактивные заказы или SOLD с билетами без bot_user_id (неконсистентное состояние)
        $orphanTickets = Ticket::where('raffle_id', $raffle->id)
            ->whereNotNull('order_id')
            ->whereNull('bot_user_id')
            ->whereHas('order', function ($q) {
                $q->whereIn('status', [Order::STATUS_EXPIRED, Order::STATUS_REJECTED])
                    ->orWhere(function ($q2) {
                        $q2->where('status', Order::STATUS_RESERVED)
                            ->where('reserved_until', '<', now());
                    })
                    ->orWhere('status', Order::STATUS_SOLD); // SOLD но билет без user — сбой при одобрении
            })
            ->count();
        if ($orphanTickets > 0) {
            $problems[] = "{$orphanTickets} билетов привязаны к неактивным/битым заказам (EXPIRED/REJECTED, просроченная бронь или SOLD без выдачи). Исправить: php artisan raffle:diagnose {$raffle->id} --fix";
        }
        
        // Проверяем расхождения в статистике
        $hasMismatch = false;
        if ($raffle->total_participants != $actualParticipants) {
            $hasMismatch = true;
            $problems[] = "Участники: кэш={$raffle->total_participants}, реально={$actualParticipants}";
        }
        if ($raffle->tickets_issued != $actualIssued) {
            $hasMismatch = true;
            $problems[] = "Выдано билетов: кэш={$raffle->tickets_issued}, реально={$actualIssued}";
        }
        $revenueDiff = abs($raffle->total_revenue - $actualRevenue);
        if ($revenueDiff > 0.01) {
            $hasMismatch = true;
            $problems[] = "Выручка: кэш={$raffle->total_revenue}, реально={$actualRevenue}";
        }
        if ($raffle->checks_count != $actualChecksCount) {
            $hasMismatch = true;
            $problems[] = "Чеков: кэш={$raffle->checks_count}, реально={$actualChecksCount}";
        }

        if (!empty($problems) || $hasMismatch) {
            $this->error("=== Обнаружены проблемы ===");
            foreach ($problems as $problem) {
                $this->line("❌ {$problem}");
            }
            if ($hasMismatch && !in_array("Выдано билетов", array_map(fn($p) => substr($p, 0, 20), $problems))) {
                $this->line("❌ Обнаружены расхождения в статистике");
            }
            $this->newLine();
            
            if ($this->option('fix') || $this->confirm('Исправить проблемы автоматически?')) {
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
        
        // 2. Освобождение «зависших» билетов: EXPIRED/REJECTED, просроченная RESERVED или SOLD без bot_user_id
        $orphanQuery = Ticket::where('raffle_id', $raffle->id)
            ->whereNotNull('order_id')
            ->whereNull('bot_user_id')
            ->whereHas('order', function ($q) {
                $q->whereIn('status', [Order::STATUS_EXPIRED, Order::STATUS_REJECTED])
                    ->orWhere(function ($q2) {
                        $q2->where('status', Order::STATUS_RESERVED)
                            ->where('reserved_until', '<', now());
                    })
                    ->orWhere('status', Order::STATUS_SOLD);
            });
        $orphanTickets = (clone $orphanQuery)->count();
        if ($orphanTickets > 0) {
            $this->line("Освобождение {$orphanTickets} билетов от неактивных/битых заказов...");
            $orphanQuery->update(['order_id' => null, 'bot_user_id' => null, 'issued_at' => null]);
            $this->info("✅ Билеты освобождены");
        }

        // 3. Проверка и пересоздание билетов если нужно
        $totalTickets = $raffle->tickets()->count();
        if ($totalTickets < $raffle->total_slots) {
            $missing = $raffle->total_slots - $totalTickets;
            $this->line("Создание {$missing} недостающих билетов...");
            
            $lastNumber = $raffle->tickets()->max('number') ?? 0;
            
            // Создаём batch-ом для скорости
            $tickets = [];
            $now = now();
            for ($i = 1; $i <= $missing; $i++) {
                $tickets[] = [
                    'telegram_bot_id' => $raffle->telegram_bot_id,
                    'raffle_id' => $raffle->id,
                    'number' => $lastNumber + $i,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            
            // Вставляем по частям (по 100 за раз)
            $chunks = array_chunk($tickets, 100);
            foreach ($chunks as $chunk) {
                Ticket::insert($chunk);
            }
            
            $this->info("✅ Создано {$missing} билетов");
        }
        
        // 4. Пересчёт всей статистики (всегда обновляем при --fix)
        $this->line("Пересчёт статистики розыгрыша...");
        $raffle->updateStatistics();
        $raffle->refresh();
        $this->info("✅ Статистика обновлена");
        
        $this->line("Новые значения:");
        $this->line("  - Участников: {$raffle->total_participants}");
        $this->line("  - Выдано билетов: {$raffle->tickets_issued}");
        $this->line("  - Выручка: {$raffle->total_revenue} ₽");
        $this->line("  - Чеков: {$raffle->checks_count}");
        $this->line("  - Доступно мест: " . ($raffle->total_slots - $raffle->tickets_issued));
        
        $this->newLine();
        $this->info("🎉 Исправление завершено!");
    }
}
