<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotSettings;
use App\Models\BotUser;
use App\Models\Raffle;
use App\Models\TelegramBot;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RaffleController extends Controller
{
    /**
     * Получить список всех розыгрышей для бота
     */
    public function index(Request $request, int $botId): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $botId)
            ->firstOrFail();

        $raffles = Raffle::where('telegram_bot_id', $bot->id)
            ->with(['winnerUser', 'winnerTicket'])
            ->orderByDesc('created_at')
            ->get();

        // Получаем текущий активный розыгрыш
        $currentRaffle = Raffle::getCurrentForBot($bot->id);

        return response()->json([
            'raffles' => $raffles,
            'current_raffle' => $currentRaffle,
        ]);
    }

    /**
     * Получить детали розыгрыша
     */
    public function show(Request $request, int $botId, int $raffleId): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $botId)
            ->firstOrFail();

        $raffle = Raffle::where('telegram_bot_id', $bot->id)
            ->where('id', $raffleId)
            ->with(['winnerUser', 'winnerTicket', 'checks', 'tickets'])
            ->firstOrFail();

        // Получаем участников с их номерками (явно добавляем phone и fio; убираем PHP serialized вид если есть)
        $participantsList = $raffle->getParticipants();
        $participants = $participantsList->map(function ($user) {
            return [
                'id' => $user->id,
                'phone' => self::ensurePlainString($user->phone),
                'fio' => self::ensurePlainString($user->fio),
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'username' => $user->username,
                'tickets' => $user->tickets->map(fn ($t) => ['id' => $t->id, 'number' => $t->number])->values()->all(),
            ];
        })->values()->all();

        // Статистика
        $stats = [
            'total_participants' => $raffle->total_participants,
            'tickets_issued' => $raffle->tickets_issued,
            'total_revenue' => $raffle->total_revenue,
            'checks_count' => $raffle->checks_count,
            'available_tickets' => $raffle->total_slots - $raffle->tickets_issued,
        ];

        return response()->json([
            'raffle' => $raffle,
            'participants' => $participants,
            'stats' => $stats,
        ]);
    }

    /**
     * Обновить розыгрыш (название, количество наклеек/слотов)
     */
    public function update(Request $request, int $botId, int $raffleId): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $botId)
            ->firstOrFail();

        $raffle = Raffle::where('telegram_bot_id', $bot->id)
            ->where('id', $raffleId)
            ->firstOrFail();

        $minSlots = max(1, (int) ($raffle->tickets_issued ?? 0));
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'total_slots' => "nullable|integer|min:{$minSlots}|max:10000",
        ]);

        if (isset($validated['name'])) {
            $raffle->name = $validated['name'];
        }
        if (isset($validated['total_slots'])) {
            $oldSlots = $raffle->total_slots;
            $raffle->total_slots = (int) $validated['total_slots'];
            if ($raffle->total_slots > $oldSlots) {
                Ticket::initializeForBot($bot->id, $raffle->total_slots, $raffle->id);
            }
        }

        $raffle->save();
        $raffle->updateStatistics();

        // Синхронизируем total_slots в BotSettings для текущего активного розыгрыша
        if ($raffle->status === Raffle::STATUS_ACTIVE) {
            $settings = BotSettings::where('telegram_bot_id', $bot->id)->first();
            if ($settings && (int) $settings->current_raffle_id === (int) $raffle->id) {
                $settings->total_slots = $raffle->total_slots;
                $settings->save();
            }
        }

        return response()->json([
            'raffle' => $raffle->fresh(['winnerUser', 'winnerTicket']),
            'message' => 'Розыгрыш обновлён',
        ]);
    }

    /**
     * Получить текущий активный розыгрыш
     */
    public function current(Request $request, int $botId): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $botId)
            ->firstOrFail();

        $raffle = Raffle::getOrCreateForBot($bot->id);
        $raffle->updateStatistics();

        // Получаем участников с их номерками для выбора победителя
        $participants = $raffle->getParticipants();
        $issuedTickets = $raffle->getIssuedTickets();

        return response()->json([
            'raffle' => $raffle,
            'participants' => $participants,
            'issued_tickets' => $issuedTickets,
            'stats' => [
                'total_participants' => $raffle->total_participants,
                'tickets_issued' => $raffle->tickets_issued,
                'total_revenue' => $raffle->total_revenue,
                'checks_count' => $raffle->checks_count,
                'available_tickets' => $raffle->total_slots - $raffle->tickets_issued,
            ],
        ]);
    }

    /**
     * Получить участников для выбора победителя
     */
    public function getParticipants(Request $request, int $botId): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $botId)
            ->firstOrFail();

        $raffle = Raffle::getCurrentForBot($bot->id);

        if (!$raffle) {
            return response()->json([
                'message' => 'Нет активного розыгрыша',
                'participants' => [],
                'issued_tickets' => [],
            ]);
        }

        // Получаем все выданные номерки с информацией о владельцах
        $issuedTickets = Ticket::where('telegram_bot_id', $bot->id)
            ->whereNotNull('bot_user_id')
            ->with('botUser')
            ->orderBy('number')
            ->get()
            ->map(function ($ticket) {
                return [
                    'id' => $ticket->id,
                    'number' => $ticket->number,
                    'user' => $ticket->botUser ? [
                        'id' => $ticket->botUser->id,
                        'username' => $ticket->botUser->username,
                        'first_name' => $ticket->botUser->first_name,
                        'fio' => $ticket->botUser->fio,
                        'telegram_user_id' => $ticket->botUser->telegram_user_id,
                    ] : null,
                ];
            });

        return response()->json([
            'raffle' => $raffle,
            'issued_tickets' => $issuedTickets,
            'total_tickets' => $issuedTickets->count(),
        ]);
    }

    /**
     * Завершить розыгрыш с выбором победителя
     */
    public function complete(Request $request, int $botId): JsonResponse
    {
        $validated = $request->validate([
            'winner_ticket_id' => 'required|integer|exists:tickets,id',
            'notes' => 'nullable|string|max:1000',
            'notify_winner' => 'nullable|boolean',
        ]);

        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $botId)
            ->firstOrFail();

        $raffle = Raffle::getCurrentForBot($bot->id);

        if (!$raffle) {
            return response()->json([
                'message' => 'Нет активного розыгрыша для завершения',
            ], 400);
        }

        // Проверяем, что номерок принадлежит этому боту и выдан
        $winnerTicket = Ticket::where('id', $validated['winner_ticket_id'])
            ->where('telegram_bot_id', $bot->id)
            ->whereNotNull('bot_user_id')
            ->with('botUser')
            ->first();

        if (!$winnerTicket) {
            return response()->json([
                'message' => 'Выбранный номерок не найден или не выдан участнику',
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Завершаем розыгрыш
            $raffle->complete($winnerTicket->id, $validated['notes'] ?? null);

            DB::commit();

            // Уведомляем победителя в Telegram
            if ($validated['notify_winner'] ?? true) {
                $this->notifyWinner($bot, $winnerTicket->botUser, $raffle);
            }

            return response()->json([
                'message' => 'Розыгрыш завершён!',
                'raffle' => $raffle->fresh(['winnerUser', 'winnerTicket']),
                'winner' => [
                    'ticket_number' => $winnerTicket->number,
                    'user' => $winnerTicket->botUser,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error completing raffle: ' . $e->getMessage());

            return response()->json([
                'message' => 'Ошибка при завершении розыгрыша: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Начать новый розыгрыш (сброс текущего)
     */
    public function reset(Request $request, int $botId): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $botId)
            ->firstOrFail();

        $settings = BotSettings::where('telegram_bot_id', $bot->id)->first();

        try {
            DB::beginTransaction();

            // Сбрасываем все номерки (отвязываем от пользователей)
            Ticket::where('telegram_bot_id', $bot->id)
                ->update([
                    'bot_user_id' => null,
                    'check_id' => null,
                    'issued_at' => null,
                    'raffle_id' => null,
                ]);

            // Создаём новый розыгрыш
            $newRaffle = Raffle::createForBot($bot->id, $validated['name'] ?? null);

            // Привязываем номерки к новому розыгрышу
            Ticket::where('telegram_bot_id', $bot->id)
                ->update(['raffle_id' => $newRaffle->id]);

            // Инициализируем номерки если их нет
            Ticket::initializeForBot($bot->id, $settings->total_slots ?? 500, $newRaffle->id);

            DB::commit();

            return response()->json([
                'message' => 'Новый розыгрыш начат!',
                'raffle' => $newRaffle,
                'tickets_stats' => Ticket::getStats($bot->id),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error resetting raffle: ' . $e->getMessage());

            return response()->json([
                'message' => 'Ошибка при создании нового розыгрыша: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Отменить текущий розыгрыш
     */
    public function cancel(Request $request, int $botId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $botId)
            ->firstOrFail();

        $raffle = Raffle::getCurrentForBot($bot->id);

        if (!$raffle) {
            return response()->json([
                'message' => 'Нет активного розыгрыша для отмены',
            ], 400);
        }

        $raffle->cancel($validated['reason'] ?? null);

        return response()->json([
            'message' => 'Розыгрыш отменён',
            'raffle' => $raffle,
        ]);
    }

    /**
     * Уведомить победителя в Telegram
     */
    private function notifyWinner(TelegramBot $bot, BotUser $winner, Raffle $raffle): void
    {
        try {
            $message = "🎉 ПОЗДРАВЛЯЕМ! 🎉\n\n";
            $message .= "Вы выиграли в розыгрыше \"{$raffle->name}\"!\n\n";
            $message .= "🎫 Ваш выигрышный номерок: {$raffle->winner_ticket_number}\n\n";
            $message .= "Свяжитесь с нами для получения приза!";

            Http::post("https://api.telegram.org/bot{$bot->token}/sendMessage", [
                'chat_id' => $winner->telegram_user_id,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

            Log::info('Winner notified', [
                'raffle_id' => $raffle->id,
                'winner_user_id' => $winner->id,
                'telegram_user_id' => $winner->telegram_user_id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to notify winner: ' . $e->getMessage());
        }
    }

    /**
     * Вернуть обычную строку: если значение — PHP serialized (s:len:"...";), распаковать.
     */
    private static function ensurePlainString(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (preg_match('/^s:\d+:"/', $value)) {
            $un = @unserialize($value);
            return is_string($un) ? $un : $value;
        }
        return $value;
    }
}
