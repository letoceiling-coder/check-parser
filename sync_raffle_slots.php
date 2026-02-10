<?php
/**
 * Одноразовая синхронизация: Raffle.total_slots = BotSettings.total_slots для активного розыгрыша.
 * Запуск: php sync_raffle_slots.php [bot_id=1]
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BotSettings;
use App\Models\Raffle;

$botId = (int) ($argv[1] ?? 1);
$settings = BotSettings::where('telegram_bot_id', $botId)->first();
if (!$settings || $settings->total_slots === null) {
    echo "Bot {$botId}: настройки не найдены или total_slots не задан.\n";
    exit(1);
}

$raffle = Raffle::getCurrentForBot($botId);
if (!$raffle) {
    echo "Bot {$botId}: активный розыгрыш не найден.\n";
    exit(0);
}

$newTotal = (int) $settings->total_slots;
$oldTotal = (int) $raffle->total_slots;
if ($oldTotal == $newTotal) {
    echo "Raffle #{$raffle->id}: total_slots уже {$newTotal}.\n";
    exit(0);
}

$raffle->total_slots = $newTotal;
$raffle->save();
echo "Raffle #{$raffle->id}: total_slots обновлён {$oldTotal} → {$newTotal}.\n";
echo "Доступно мест в боте теперь: " . ($newTotal - (int) $raffle->tickets_issued) . "\n";
