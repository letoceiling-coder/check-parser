<?php
// Скрипт инициализации билетов для розыгрыша

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Ticket;
use App\Models\Raffle;

$raffleId = $argv[1] ?? 1;

$raffle = Raffle::find($raffleId);

if (!$raffle) {
    echo "Розыгрыш #{$raffleId} не найден\n";
    exit(1);
}

echo "Розыгрыш #{$raffle->id}: {$raffle->name}\n";
echo "Всего мест: {$raffle->total_slots}\n";

$existing = Ticket::where('raffle_id', $raffle->id)->count();
echo "Уже создано билетов: {$existing}\n";

if ($existing >= $raffle->total_slots) {
    echo "✅ Билеты уже инициализированы\n";
    exit(0);
}

$missing = $raffle->total_slots - $existing;
echo "Нужно создать: {$missing} билетов\n";

$lastNumber = Ticket::where('raffle_id', $raffle->id)->max('number') ?? 0;

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

// Вставляем по частям
$chunks = array_chunk($tickets, 100);
foreach ($chunks as $index => $chunk) {
    Ticket::insert($chunk);
    echo "  Создано " . (($index + 1) * count($chunk)) . " / {$missing}\r";
}

echo "\n✅ Создано {$missing} билетов\n";

$total = Ticket::where('raffle_id', $raffle->id)->count();
echo "Всего билетов в БД: {$total}\n";
