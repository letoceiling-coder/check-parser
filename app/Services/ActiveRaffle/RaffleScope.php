<?php

namespace App\Services\ActiveRaffle;

/**
 * Scope для определения активного розыгрыша.
 * Если telegram_bot_id задан — активный один на бота; иначе глобально один.
 */
final class RaffleScope
{
    public function __construct(
        public readonly ?int $telegramBotId = null
    ) {
    }

    public static function forBot(int $telegramBotId): self
    {
        return new self($telegramBotId);
    }

    public static function global(): self
    {
        return new self(null);
    }
}
