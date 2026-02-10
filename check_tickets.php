<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Ticket;

echo "=== Проверка билетов ===\n\n";

// Всего билетов
$total = Ticket::count();
echo "Всего билетов в БД: {$total}\n";

// По раффлам
$byRaffle = Ticket::selectRaw('raffle_id, COUNT(*) as count')
    ->groupBy('raffle_id')
    ->get();

echo "\nПо розыгрышам:\n";
foreach ($byRaffle as $row) {
    echo "  Raffle #{$row->raffle_id}: {$row->count} билетов\n";
}

// Билеты с raffle_id = 1
$raffle1Total = Ticket::where('raffle_id', 1)->count();
$raffle1Free = Ticket::where('raffle_id', 1)->whereNull('bot_user_id')->whereNull('order_id')->count();
$raffle1Reserved = Ticket::where('raffle_id', 1)->whereNotNull('order_id')->whereNull('bot_user_id')->count();
$raffle1Sold = Ticket::where('raffle_id', 1)->whereNotNull('bot_user_id')->count();

echo "\nРозыгрыш #1 детально:\n";
echo "  Всего: {$raffle1Total}\n";
echo "  Свободных: {$raffle1Free}\n";
echo "  Забронировано: {$raffle1Reserved}\n";
echo "  Продано: {$raffle1Sold}\n";

// Билеты без raffle_id
$noRaffle = Ticket::whereNull('raffle_id')->count();
if ($noRaffle > 0) {
    echo "\n⚠️ Билеты БЕЗ raffle_id: {$noRaffle}\n";
}

// Пример первых 5 билетов
echo "\nПервые 5 билетов:\n";
$samples = Ticket::orderBy('id')->limit(5)->get(['id', 'telegram_bot_id', 'raffle_id', 'number', 'bot_user_id', 'order_id']);
foreach ($samples as $t) {
    echo "  ID:{$t->id} Bot:{$t->telegram_bot_id} Raffle:{$t->raffle_id} Number:{$t->number} User:{$t->bot_user_id} Order:{$t->order_id}\n";
}
