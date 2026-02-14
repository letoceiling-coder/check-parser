<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\Raffle;
use App\Models\BotUser;
use App\Models\TelegramBot;
use App\Models\BotSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Автоматическое снятие брони через 30 минут с момента создания заказа.
 * Критерии: RESERVED и reserved_until истекло ИЛИ REVIEW и прошло 30 мин с created_at.
 * После снятия: билеты в пул, статус expired, уведомление владельцу и другим участникам розыгрыша.
 */
class ClearExpiredOrdersCommand extends Command
{
    /** Время жизни брони в минутах (с момента создания заказа). */
    private const RESERVATION_MINUTES = 30;

    protected $signature = 'orders:clear-expired';

    protected $description = 'Очистить просроченные брони заказов (RESERVED/REVIEW > 30 мин с создания)';

    public function handle(): int
    {
        $this->info('Checking for expired orders...');

        // Просроченные брони: RESERVED с истёкшим reserved_until ИЛИ REVIEW старше 30 мин с created_at
        $expiredOrders = Order::where(function ($q) {
            $q->where('status', Order::STATUS_RESERVED)
                ->where('reserved_until', '<', now());
        })
            ->orWhere(function ($q) {
                $q->where('status', Order::STATUS_REVIEW)
                    ->where('created_at', '<', now()->subMinutes(self::RESERVATION_MINUTES));
            })
            ->with(['botUser', 'raffle', 'telegramBot'])
            ->get();

        if ($expiredOrders->isEmpty()) {
            $this->info('No expired orders found.');
            $this->releaseOrphanedTickets();
            return 0;
        }

        $this->info("Found {$expiredOrders->count()} expired orders.");

        foreach ($expiredOrders as $order) {
            try {
                DB::transaction(function () use ($order) {
                    // Идемпотентность: перечитываем заказ под блокировкой (race: пользователь мог только что отправить PDF → REVIEW)
                    $orderLocked = Order::where('id', $order->id)->lockForUpdate()->with(['botUser', 'raffle', 'telegramBot'])->first();
                    if (!$orderLocked) {
                        return;
                    }
                    // Не переводим в expired, если статус уже изменился (например на REVIEW при приёме чека)
                    $isReservedExpired = $orderLocked->status === Order::STATUS_RESERVED
                        && $orderLocked->reserved_until
                        && $orderLocked->reserved_until->isPast();
                    $isReviewExpired = $orderLocked->status === Order::STATUS_REVIEW
                        && $orderLocked->created_at
                        && $orderLocked->created_at->lt(now()->subMinutes(self::RESERVATION_MINUTES));
                    if (!$isReservedExpired && !$isReviewExpired) {
                        return;
                    }

                    // Освобождаем билеты (возврат в общий пул)
                    $releasedCount = Ticket::where('order_id', $orderLocked->id)
                        ->update([
                            'order_id' => null,
                            'bot_user_id' => null,
                            'issued_at' => null,
                        ]);

                    if ($orderLocked->raffle) {
                        $orderLocked->raffle->decrement('tickets_issued', $releasedCount);
                    }

                    $orderLocked->status = Order::STATUS_EXPIRED;
                    $orderLocked->reject_reason = 'Время брони истекло';
                    $orderLocked->save();

                    Log::info("Order #{$orderLocked->id} expired and cleared", [
                        'user_id' => $orderLocked->bot_user_id,
                        'released_tickets' => $releasedCount,
                    ]);

                    if ($orderLocked->botUser && $orderLocked->telegramBot) {
                        $this->notifyUser($orderLocked);
                    }

                    if ($orderLocked->botUser) {
                        $orderLocked->botUser->resetState();
                    }

                    $this->notifyOtherParticipants($orderLocked);
                });

                $this->info("✓ Order #{$order->id} cleared");

            } catch (\Exception $e) {
                $this->error("✗ Failed to clear order #{$order->id}: " . $e->getMessage());
                Log::error('Clear expired order failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Профилактика: освободить билеты, всё ещё привязанные к EXPIRED/REJECTED (ручное изменение в БД, старый код)
        $this->releaseOrphanedTickets();

        $this->info('Done.');
        return 0;
    }

    /**
     * Освободить «зависшие» билеты: заказ EXPIRED/REJECTED, просроченная RESERVED или SOLD с билетами без bot_user_id.
     * Запускается при каждом вызове команды, чтобы не накапливались несовпадения всего/свободно.
     */
    private function releaseOrphanedTickets(): void
    {
        $updated = Ticket::whereNotNull('order_id')
            ->whereNull('bot_user_id')
            ->whereHas('order', function ($q) {
                $q->whereIn('status', [Order::STATUS_EXPIRED, Order::STATUS_REJECTED, Order::STATUS_SOLD])
                    ->orWhere(function ($q2) {
                        $q2->where('status', Order::STATUS_RESERVED)
                            ->where('reserved_until', '<', now());
                    });
            })
            ->update(['order_id' => null, 'bot_user_id' => null, 'issued_at' => null]);

        if ($updated > 0) {
            Log::info("Released orphaned tickets", ['count' => $updated]);
            $this->info("Released {$updated} orphaned ticket(s).");
        }
    }

    /**
     * Уведомить пользователя об истечении брони
     */
    private function notifyUser(Order $order): void
    {
        $bot = $order->telegramBot;
        $user = $order->botUser;
        
        $settings = BotSettings::where('telegram_bot_id', $bot->id)->first();
        
        $message = $settings?->msg_order_expired ?? 
            "⏰ Время брони истекло!\n\n" .
            "Ваш заказ на {quantity} шт. отменён.\n" .
            "Места освобождены и доступны для других участников.\n\n" .
            "Вы можете оформить новую заявку, нажав /start";
        
        $message = str_replace('{quantity}', $order->quantity, $message);
        
        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$bot->token}/sendMessage", [
                'chat_id' => $user->telegram_user_id,
                'text' => $message,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to notify user about expired order', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Уведомить остальных участников розыгрыша об освободившихся местах
     * (кроме пользователя, у которого снята бронь).
     */
    private function notifyOtherParticipants(Order $order): void
    {
        if (!$order->raffle_id || !$order->telegramBot) {
            return;
        }

        $otherParticipantIds = Ticket::where('raffle_id', $order->raffle_id)
            ->whereNotNull('bot_user_id')
            ->where('bot_user_id', '!=', $order->bot_user_id)
            ->distinct()
            ->pluck('bot_user_id');

        if ($otherParticipantIds->isEmpty()) {
            return;
        }

        $users = BotUser::whereIn('id', $otherParticipantIds)->get();
        $message = 'Освободились места, вы можете купить наклейки.';
        $bot = $order->telegramBot;

        foreach ($users as $user) {
            if (empty($user->telegram_user_id)) {
                continue;
            }
            try {
                Http::timeout(10)->post("https://api.telegram.org/bot{$bot->token}/sendMessage", [
                    'chat_id' => $user->telegram_user_id,
                    'text' => $message,
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to notify raffle participant about freed slots', [
                    'order_id' => $order->id,
                    'bot_user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
