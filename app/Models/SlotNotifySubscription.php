<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlotNotifySubscription extends Model
{
    protected $fillable = ['telegram_bot_id', 'raffle_id', 'bot_user_id'];

    public function telegramBot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class);
    }

    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class);
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class);
    }

    public static function subscribe(int $telegramBotId, int $raffleId, int $botUserId): self
    {
        return self::firstOrCreate(
            [
                'telegram_bot_id' => $telegramBotId,
                'raffle_id' => $raffleId,
                'bot_user_id' => $botUserId,
            ],
            []
        );
    }
}
