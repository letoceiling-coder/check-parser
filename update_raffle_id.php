<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Ticket;
use App\Models\Raffle;

// Текущее состояние
$nullCount = Ticket::whereNull('raffle_id')->count();
$raffle1Count = Ticket::where('raffle_id', 1)->count();

echo "BEFORE:\n";
echo "  NULL raffle_id: {$nullCount}\n";
echo "  raffle_id = 1: {$raffle1Count}\n\n";

// Обновление
if ($nullCount > 0) {
    $updated = Ticket::where('telegram_bot_id', 1)
        ->whereNull('raffle_id')
        ->update(['raffle_id' => 1]);
    
    echo "UPDATED: {$updated} tickets\n\n";
}

// После
$nullCount = Ticket::whereNull('raffle_id')->count();
$raffle1Count = Ticket::where('raffle_id', 1)->count();

echo "AFTER:\n";
echo "  NULL raffle_id: {$nullCount}\n";
echo "  raffle_id = 1: {$raffle1Count}\n";
