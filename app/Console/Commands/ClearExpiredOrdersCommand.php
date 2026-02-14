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
            return 0;
        }

        $this->info("Found {$expiredOrders->count()} expired orders.");

        foreach ($expiredOrders as $order) {
            try {
                DB::transaction(function () use ($order) {
                    // Освобождаем билеты (возврат в общий пул)
                    $releasedCount = Ticket::where('order_id', $order->id)
                        ->update([
                            'order_id' => null,
                            'bot_user_id' => null,
                            'issued_at' => null,
                        ]);

                    if ($order->raffle) {
                        $order->raffle->decrement('tickets_issued', $releasedCount);
                    }

                    $order->status = Order::STATUS_EXPIRED;
                    $order->reject_reason = 'Время брони истекло';
                    $order->save();

                    Log::info("Order #{$order->id} expired and cleared", [
                        'user_id' => $order->bot_user_id,
                        'released_tickets' => $releasedCount,
                    ]);

                    // Уведомляем пользователя, у которого снята бронь
                    if ($order->botUser && $order->telegramBot) {
                        $this->notifyUser($order);
                    }

                    if ($order->botUser) {
                        $order->botUser->resetState();
                    }

                    // Уведомляем остальных участников розыгрыша об освободившихся местах
                    $this->notifyOtherParticipants($order);
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

        $this->info('Done.');
        return 0;
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
