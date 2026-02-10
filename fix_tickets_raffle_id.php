<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Ticket;
use App\Models\Raffle;

echo "=== Исправление raffle_id для билетов ===\n\n";

// Находим активный розыгрыш
$raffle = Raffle::where('status', 'active')->first();

if (!$raffle) {
    echo "❌ Активный розыгрыш не найден\n";
    exit(1);
}

echo "Активный розыгрыш: #{$raffle->id} - {$raffle->name}\n";
echo "Telegram Bot ID: {$raffle->telegram_bot_id}\n\n";

// Находим билеты без raffle_id для этого бота
$ticketsToFix = Ticket::where('telegram_bot_id', $raffle->telegram_bot_id)
    ->whereNull('raffle_id')
    ->count();

echo "Билетов без raffle_id: {$ticketsToFix}\n";

if ($ticketsToFix == 0) {
    echo "✅ Все билеты уже имеют raffle_id\n";
    exit(0);
}

// Обновляем
$updated = Ticket::where('telegram_bot_id', $raffle->telegram_bot_id)
    ->whereNull('raffle_id')
    ->update(['raffle_id' => $raffle->id]);

echo "✅ Обновлено билетов: {$updated}\n\n";

// Проверка
$free = Ticket::where('raffle_id', $raffle->id)
    ->whereNull('bot_user_id')
    ->whereNull('order_id')
    ->count();

echo "Свободных билетов для раffle #{$raffle->id}: {$free}\n";
echo "\n✅ Исправление завершено!\n";
