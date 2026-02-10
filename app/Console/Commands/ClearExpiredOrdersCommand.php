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

class ClearExpiredOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:clear-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Очистить просроченные брони заказов (RESERVED > 30 мин)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking for expired orders...');
        
        // Находим просроченные брони
        $expiredOrders = Order::where('status', Order::STATUS_RESERVED)
            ->where('reserved_until', '<', now())
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
                    // Освобождаем билеты
                    $releasedCount = Ticket::where('order_id', $order->id)
                        ->update([
                            'order_id' => null,
                            'bot_user_id' => null,
                            'issued_at' => null,
                        ]);
                    
                    // Обновляем статистику розыгрыша
                    if ($order->raffle) {
                        $order->raffle->decrement('tickets_issued', $releasedCount);
                    }
                    
                    // Обновляем статус заказа
                    $order->status = Order::STATUS_EXPIRED;
                    $order->reject_reason = 'Время брони истекло';
                    $order->save();
                    
                    Log::info("Order #{$order->id} expired and cleared", [
                        'user_id' => $order->bot_user_id,
                        'released_tickets' => $releasedCount,
                    ]);
                    
                    // Уведомляем пользователя
                    if ($order->botUser && $order->telegramBot) {
                        $this->notifyUser($order);
                    }
                    
                    // Сбрасываем FSM пользователя
                    if ($order->botUser) {
                        $order->botUser->resetState();
                    }
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
}
