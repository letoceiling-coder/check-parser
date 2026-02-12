<?php

namespace App\Services;

use App\Exceptions\NoActiveRaffleException;
use App\Models\BotSettings;
use App\Models\Raffle;
use App\Services\ActiveRaffle\RaffleScope;

/**
 * Единый источник истины для активного розыгрыша.
 * Использовать ТОЛЬКО этот сервис для получения активного розыгрыша (backend + bot + api).
 */
class ActiveRaffleResolver
{
    /**
     * Получить активный розыгрыш или null.
     *
     * @param  RaffleScope|null  $scope  Scope (например по telegram_bot_id). Если null — глобально один активный.
     */
    public function getActive(?RaffleScope $scope = null): ?Raffle
    {
        $scope = $scope ?? RaffleScope::global();

        if ($scope->telegramBotId !== null) {
            return $this->getActiveForBot($scope->telegramBotId);
        }

        // Глобально: один активный по всей системе (первый попавшийся по id)
        return Raffle::where('status', Raffle::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Получить активный розыгрыш или выбросить исключение.
     *
     * @param  RaffleScope|null  $scope
     * @throws NoActiveRaffleException
     */
    public function requireActive(?RaffleScope $scope = null): Raffle
    {
        $raffle = $this->getActive($scope);
        if ($raffle !== null) {
            return $raffle;
        }
        throw new NoActiveRaffleException();
    }

    /**
     * Активный розыгрыш для бота: сначала из BotSettings.current_raffle_id (если он active),
     * иначе по статусу (должен быть ровно один active на бота).
     */
    private function getActiveForBot(int $telegramBotId): ?Raffle
    {
        $settings = BotSettings::where('telegram_bot_id', $telegramBotId)->first();
        if ($settings && $settings->current_raffle_id) {
            $raffle = Raffle::find($settings->current_raffle_id);
            if ($raffle && $raffle->telegram_bot_id === $telegramBotId && $raffle->status === Raffle::STATUS_ACTIVE) {
                return $raffle;
            }
        }

        // Fallback: по статусу (должен быть один после миграции и транзакций)
        return Raffle::where('telegram_bot_id', $telegramBotId)
            ->where('status', Raffle::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();
    }
}
