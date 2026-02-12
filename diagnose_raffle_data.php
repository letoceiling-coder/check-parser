<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Raffle;
use App\Models\Ticket;
use App\Models\Check;
use App\Models\BotUser;
use App\Models\TelegramBot;

echo "=== ДИАГНОСТИКА ДАННЫХ РОЗЫГРЫША ===\n\n";

// Получаем первого бота
$bot = TelegramBot::first();
if (!$bot) {
    echo "❌ Бот не найден\n";
    exit(1);
}

echo "Бот ID: {$bot->id}\n\n";

// Получаем активный розыгрыш
$activeRaffle = Raffle::resolveActiveForBot($bot->id);

if (!$activeRaffle) {
    echo "❌ Активный розыгрыш не найден\n";
    exit(1);
}

echo "=== АКТИВНЫЙ РОЗЫГРЫШ ===\n";
echo "ID: {$activeRaffle->id}\n";
echo "Название: {$activeRaffle->name}\n";
echo "Статус: {$activeRaffle->status}\n";
echo "Всего слотов: {$activeRaffle->total_slots}\n";
echo "\n";

// Текущие кэшированные значения
echo "=== КЭШИРОВАННЫЕ ЗНАЧЕНИЯ (из таблицы raffles) ===\n";
echo "total_participants: {$activeRaffle->total_participants}\n";
echo "tickets_issued: {$activeRaffle->tickets_issued}\n";
echo "total_revenue: {$activeRaffle->total_revenue}\n";
echo "checks_count: {$activeRaffle->checks_count}\n";
echo "Доступно (по кэшу): " . ($activeRaffle->total_slots - $activeRaffle->tickets_issued) . "\n";
echo "\n";

// Реальные данные из БД
echo "=== РЕАЛЬНЫЕ ДАННЫЕ ИЗ БД ===\n";

// Участники (уникальные пользователи с билетами в этом розыгрыше)
$realParticipants = BotUser::whereHas('tickets', function ($query) use ($activeRaffle) {
    $query->where('raffle_id', $activeRaffle->id);
})->count();
echo "Реальных участников: {$realParticipants}\n";

// Выданные билеты (только с bot_user_id - реально выданные)
// Билеты с order_id но без bot_user_id - это только бронь, они не считаются выданными
$realTicketsIssued = Ticket::where('raffle_id', $activeRaffle->id)
    ->whereNotNull('bot_user_id')
    ->count();
echo "Реально выдано билетов: {$realTicketsIssued}\n";

// Доступные места
$realAvailable = $activeRaffle->total_slots - $realTicketsIssued;
echo "Реально доступно мест: {$realAvailable}\n";

// Выручка (только одобренные чеки)
$realRevenue = Check::where('raffle_id', $activeRaffle->id)
    ->where('review_status', 'approved')
    ->get()
    ->sum(function ($check) {
        return $check->admin_edited_amount ?? $check->corrected_amount ?? $check->amount ?? 0;
    });
echo "Реальная выручка: {$realRevenue} ₽\n";

// Количество чеков
$realChecksCount = Check::where('raffle_id', $activeRaffle->id)->count();
echo "Реальное количество чеков: {$realChecksCount}\n";

// Количество одобренных чеков
$approvedChecksCount = Check::where('raffle_id', $activeRaffle->id)
    ->where('review_status', 'approved')
    ->count();
echo "Одобренных чеков: {$approvedChecksCount}\n";

echo "\n";

// Сравнение
echo "=== СРАВНЕНИЕ ===\n";
$issues = [];

if ($activeRaffle->total_participants != $realParticipants) {
    $issues[] = "❌ Участники: кэш={$activeRaffle->total_participants}, реально={$realParticipants}";
} else {
    echo "✅ Участники совпадают: {$realParticipants}\n";
}

if ($activeRaffle->tickets_issued != $realTicketsIssued) {
    $issues[] = "❌ Выдано билетов: кэш={$activeRaffle->tickets_issued}, реально={$realTicketsIssued}";
} else {
    echo "✅ Выдано билетов совпадает: {$realTicketsIssued}\n";
}

$revenueDiff = abs($activeRaffle->total_revenue - $realRevenue);
if ($revenueDiff > 0.01) {
    $issues[] = "❌ Выручка: кэш={$activeRaffle->total_revenue}, реально={$realRevenue} (разница: {$revenueDiff})";
} else {
    echo "✅ Выручка совпадает: {$realRevenue} ₽\n";
}

if ($activeRaffle->checks_count != $realChecksCount) {
    $issues[] = "❌ Количество чеков: кэш={$activeRaffle->checks_count}, реально={$realChecksCount}";
} else {
    echo "✅ Количество чеков совпадает: {$realChecksCount}\n";
}

if (!empty($issues)) {
    echo "\n=== НАЙДЕНЫ РАСХОЖДЕНИЯ ===\n";
    foreach ($issues as $issue) {
        echo $issue . "\n";
    }
    echo "\n";
    echo "Обновляю статистику...\n";
    $activeRaffle->updateStatistics();
    $activeRaffle->refresh();
    echo "✅ Статистика обновлена\n";
    echo "\nНовые значения:\n";
    echo "total_participants: {$activeRaffle->total_participants}\n";
    echo "tickets_issued: {$activeRaffle->tickets_issued}\n";
    echo "total_revenue: {$activeRaffle->total_revenue}\n";
    echo "checks_count: {$activeRaffle->checks_count}\n";
    echo "Доступно: " . ($activeRaffle->total_slots - $activeRaffle->tickets_issued) . "\n";
} else {
    echo "\n✅ Все данные совпадают!\n";
}

echo "\n=== ДОПОЛНИТЕЛЬНАЯ ИНФОРМАЦИЯ ===\n";
echo "Билеты только с bot_user_id: " . Ticket::where('raffle_id', $activeRaffle->id)->whereNotNull('bot_user_id')->count() . "\n";
echo "Билеты только с order_id: " . Ticket::where('raffle_id', $activeRaffle->id)->whereNotNull('order_id')->whereNull('bot_user_id')->count() . "\n";
echo "Билеты с обоими: " . Ticket::where('raffle_id', $activeRaffle->id)->whereNotNull('bot_user_id')->whereNotNull('order_id')->count() . "\n";

echo "\n=== ВСЕ РОЗЫГРЫШИ ДЛЯ ЭТОГО БОТА ===\n";
$allRaffles = Raffle::where('telegram_bot_id', $bot->id)->orderByDesc('id')->get();
foreach ($allRaffles as $r) {
    $marker = ($r->id == $activeRaffle->id) ? "👉 АКТИВНЫЙ" : "";
    echo "ID: {$r->id}, Название: {$r->name}, Статус: {$r->status} {$marker}\n";
}

echo "\n";
