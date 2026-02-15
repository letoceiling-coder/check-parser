<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotSettings;
use App\Models\Order;
use App\Models\Raffle;
use App\Models\SlotNotifySubscription;
use App\Models\TelegramBot;
use App\Models\Ticket;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class RaffleSettingsController extends Controller
{
    /**
     * Получить настройки розыгрыша
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $bot = TelegramBot::where('user_id', $request->user()->id)
                ->where('id', $id)
                ->firstOrFail();

            $settings = BotSettings::getOrCreate($bot->id);
            $activeRaffle = Raffle::resolveActiveForBot($bot->id);
            if ($activeRaffle && (int) $settings->current_raffle_id !== (int) $activeRaffle->id) {
                $settings->current_raffle_id = $activeRaffle->id;
                $settings->save();
            }

            $ticketsStats = Ticket::getStats($bot->id, $activeRaffle?->id);

            // Списки для карточек «Выдано» и «Брони»
            $issuedUsers = [];
            $reservations = [];
            if ($activeRaffle) {
                $issuedTickets = Ticket::where('raffle_id', $activeRaffle->id)
                    ->whereNotNull('bot_user_id')
                    ->with('botUser')
                    ->orderBy('bot_user_id')
                    ->get();
                $byUser = $issuedTickets->groupBy('bot_user_id');
                foreach ($byUser as $botUserId => $tickets) {
                    $user = $tickets->first()->botUser;
                    $issuedUsers[] = [
                        'bot_user_id' => (int) $botUserId,
                        'user_name' => $user ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) : '—',
                        'username' => $user?->username ?? null,
                        'tickets_count' => $tickets->count(),
                        'ticket_numbers' => $tickets->pluck('number')->sort()->values()->all(),
                    ];
                }
                $reservationOrders = Order::where('raffle_id', $activeRaffle->id)
                    ->where('status', Order::STATUS_RESERVED)
                    ->with('botUser')
                    ->orderBy('reserved_until')
                    ->get();
                foreach ($reservationOrders as $order) {
                    $user = $order->botUser;
                    $reservedUntil = $order->reserved_until ? $order->reserved_until->timezone('Europe/Moscow') : null;
                    $minutesLeft = $reservedUntil && $reservedUntil->isFuture() ? (int) $reservedUntil->diffInMinutes(now()) : 0;
                    $reservations[] = [
                        'order_id' => $order->id,
                        'bot_user_id' => $order->bot_user_id,
                        'user_name' => $user ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) : '—',
                        'username' => $user?->username ?? null,
                        'quantity' => $order->quantity,
                        'reserved_until' => $reservedUntil ? $reservedUntil->toIso8601String() : null,
                        'minutes_left' => $minutesLeft,
                    ];
                }
            }

            $settingsArray = $settings->toArray();
            if ($activeRaffle) {
                $settingsArray['total_slots'] = $activeRaffle->total_slots;
                $settingsArray['slot_price'] = $activeRaffle->slot_price;
                $settingsArray['slots_mode'] = $activeRaffle->slots_mode ?? $settings->slots_mode;
                $settingsArray['raffle_info'] = $activeRaffle->raffle_info ?? $settings->raffle_info;
                $settingsArray['prize_description'] = $activeRaffle->prize_description ?? $settings->prize_description;
            }

            return response()->json([
                'settings' => $settingsArray,
                'active_raffle_missing' => !$activeRaffle,
                'current_raffle' => $activeRaffle ? [
                    'id' => $activeRaffle->id,
                    'name' => $activeRaffle->name,
                    'total_slots' => $activeRaffle->total_slots,
                    'slot_price' => $activeRaffle->slot_price,
                ] : null,
                'tickets_stats' => $ticketsStats,
                'issued_users' => $issuedUsers,
                'reservations' => $reservations,
                'qr_image_url' => $this->getQrImageUrlForApi($bot->id, $settings),
                'default_messages' => BotSettings::DEFAULTS,
            ]);
        } catch (\Throwable $e) {
            Log::error('RaffleSettings show error: ' . $e->getMessage(), [
                'id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Ошибка загрузки настроек',
            ], 500);
        }
    }

    /**
     * Обновить настройки розыгрыша
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $bot = TelegramBot::where('user_id', $request->user()->id)
                ->where('id', $id)
                ->firstOrFail();

            $settings = BotSettings::getOrCreate($bot->id);

            $validated = $request->validate([
                'total_slots' => 'nullable|integer|min:1|max:10000',
                'slot_price' => 'nullable|numeric|min:1',
                'slots_mode' => 'nullable|in:sequential,random',
                'is_active' => 'nullable|boolean',
                'receipt_parser_method' => 'nullable|in:legacy,enhanced,enhanced_ai',
                'payment_description' => 'nullable|string|max:255',
                'support_contact' => 'nullable|string|max:255',
                'raffle_info' => 'nullable|string|max:4000',
                'prize_description' => 'nullable|string|max:500',
                // Сообщения бота
                'msg_welcome' => 'nullable|string|max:4000',
                'msg_no_slots' => 'nullable|string|max:4000',
                'msg_ask_fio' => 'nullable|string|max:4000',
                'msg_ask_phone' => 'nullable|string|max:4000',
                'msg_ask_inn' => 'nullable|string|max:4000',
                'msg_confirm_data' => 'nullable|string|max:4000',
                'msg_show_qr' => 'nullable|string|max:4000',
                'msg_wait_check' => 'nullable|string|max:4000',
                'msg_check_received' => 'nullable|string|max:4000',
                'msg_check_approved' => 'nullable|string|max:4000',
                'msg_check_rejected' => 'nullable|string|max:4000',
                'msg_check_duplicate' => 'nullable|string|max:4000',
                'msg_admin_request_sent' => 'nullable|string|max:4000',
                'msg_admin_request_approved' => 'nullable|string|max:4000',
                'msg_admin_request_rejected' => 'nullable|string|max:4000',
                'msg_about_raffle' => 'nullable|string|max:4000',
                'msg_my_tickets' => 'nullable|string|max:4000',
                'msg_no_tickets' => 'nullable|string|max:4000',
                'msg_support' => 'nullable|string|max:4000',
            ]);

            $activeRaffle = Raffle::resolveActiveForBot($bot->id);
            if (!$activeRaffle) {
                return response()->json([
                    'message' => 'Нет активного розыгрыша. Создайте или активируйте розыгрыш на странице Розыгрыши, затем сохраняйте настройки.',
                ], 400);
            }
            if ((int) $settings->current_raffle_id !== (int) $activeRaffle->id) {
                $settings->current_raffle_id = $activeRaffle->id;
                $settings->save();
            }

            $raffleFields = ['total_slots', 'slot_price', 'slots_mode', 'raffle_info', 'prize_description'];
            $rafflePayload = array_intersect_key($validated, array_flip($raffleFields));
            $rafflePayload = array_filter($rafflePayload, fn ($v) => $v !== null);
            if ($activeRaffle && !empty($rafflePayload)) {
                $activeRaffle->update($rafflePayload);
            }

            $allowed = array_flip($settings->getFillable());
            $toFill = array_filter($validated, fn ($v) => $v !== null);
            $toFill = array_diff_key($toFill, array_flip($raffleFields));
            $toFill = array_intersect_key($toFill, $allowed);
            $settings->fill($toFill);
            $settings->save();

            if (isset($validated['total_slots']) && $activeRaffle) {
                $newTotal = (int) $validated['total_slots'];
                $raffleId = $activeRaffle->id;
                $currentCount = Ticket::where('telegram_bot_id', $bot->id)->where('raffle_id', $raffleId)->count();
                if ($newTotal < $currentCount) {
                    Ticket::reduceToTotal($bot->id, $newTotal, $raffleId);
                }
                Ticket::initializeForBot($bot->id, $newTotal, $raffleId);
            }

            $settingsResponse = $settings->fresh()->toArray();
            if ($activeRaffle) {
                $activeRaffle->refresh();
                $settingsResponse['total_slots'] = $activeRaffle->total_slots;
                $settingsResponse['slot_price'] = $activeRaffle->slot_price;
                $settingsResponse['slots_mode'] = $activeRaffle->slots_mode ?? $settingsResponse['slots_mode'];
                $settingsResponse['raffle_info'] = $activeRaffle->raffle_info ?? $settingsResponse['raffle_info'] ?? '';
                $settingsResponse['prize_description'] = $activeRaffle->prize_description ?? $settingsResponse['prize_description'] ?? '';
            }

            return response()->json([
                'message' => 'Настройки сохранены',
                'settings' => $settingsResponse,
                'current_raffle' => $activeRaffle ? [
                    'id' => $activeRaffle->id,
                    'name' => $activeRaffle->name,
                    'total_slots' => $activeRaffle->total_slots,
                    'slot_price' => $activeRaffle->slot_price,
                ] : null,
                'tickets_stats' => Ticket::getStats($bot->id, $activeRaffle?->id),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('RaffleSettings update error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Ошибка при сохранении настроек',
            ], 500);
        }
    }

    /**
     * Загрузить QR-код
     */
    public function uploadQr(Request $request, int $id): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $request->validate([
            'qr_image' => 'required|image|max:5120', // Max 5MB
        ]);

        $settings = BotSettings::getOrCreate($bot->id);

        // Удаляем старый файл если он не дефолтный
        if ($settings->qr_image_path && $settings->qr_image_path !== 'bot-assets/default-qr.jpg') {
            Storage::disk('public')->delete($settings->qr_image_path);
        }

        // Сохраняем новый файл
        $path = $request->file('qr_image')->store('bot-assets', 'public');

        $settings->qr_image_path = $path;
        $settings->save();

        return response()->json([
            'message' => 'QR-код загружен',
            'qr_image_url' => $this->getQrImageUrlForApi($id, $settings),
            'qr_image_path' => $settings->qr_image_path,
        ]);
    }

    /**
     * Инициализировать номерки
     */
    public function initializeTickets(Request $request, int $id): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $settings = BotSettings::getOrCreate($bot->id);
        $activeRaffle = Raffle::resolveActiveForBot($bot->id);
        if (!$activeRaffle) {
            return response()->json([
                'message' => 'Нет активного розыгрыша. Активируйте розыгрыш на странице Розыгрыши.',
            ], 400);
        }
        $totalSlots = $activeRaffle->total_slots ?? $settings->total_slots ?? 500;

        Ticket::initializeForBot($bot->id, $totalSlots, $activeRaffle->id);

        return response()->json([
            'message' => 'Номерки инициализированы',
            'tickets_stats' => Ticket::getStats($bot->id, $activeRaffle->id),
        ]);
    }

    /**
     * Отменить бронь заказа (из попапа «Брони»). Уведомляет пользователя в Telegram и подписчиков «уведомить о местах».
     */
    public function cancelReservation(Request $request, int $id, int $orderId): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $order = Order::where('telegram_bot_id', $bot->id)
            ->where('id', $orderId)
            ->with(['botUser', 'raffle'])
            ->firstOrFail();

        if (!$order->isReserved()) {
            return response()->json([
                'message' => 'Заказ не в статусе брони или уже обработан.',
            ], 400);
        }

        $raffleId = $order->raffle_id;
        $botUserId = $order->bot_user_id;
        $chatId = $order->botUser?->telegram_user_id;

        if (!$order->cancelReservation('Бронь отменена администратором')) {
            return response()->json(['message' => 'Не удалось отменить бронь.'], 500);
        }

        $settings = BotSettings::getOrCreate($bot->id);
        $telegram = new TelegramService($bot);

        // Уведомление пользователю, чью бронь сняли
        $msgCancelled = $settings->msg_reservation_cancelled ?? "⚠️ Ваша бронь снята администратором.\n\nВы можете снова забронировать места через бота (/start).";
        if ($chatId) {
            try {
                $telegram->sendMessage($chatId, $msgCancelled);
            } catch (\Throwable $e) {
                Log::warning('Failed to notify user about cancelled reservation', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            }
        }

        // Уведомление подписчикам «появились места»
        $msgSlotsAvailable = $settings->getMessage('slots_available');
        $subs = SlotNotifySubscription::where('telegram_bot_id', $bot->id)
            ->where('raffle_id', $raffleId)
            ->with('botUser')
            ->get();
        foreach ($subs as $sub) {
            if ($sub->bot_user_id === $botUserId) {
                continue; // не слать тому, кого только что сняли
            }
            $subChatId = $sub->botUser?->telegram_user_id;
            if ($subChatId) {
                try {
                    $telegram->sendMessage($subChatId, $msgSlotsAvailable);
                } catch (\Throwable $e) {
                    Log::warning('Failed to notify slot subscriber', ['subscription_id' => $sub->id, 'error' => $e->getMessage()]);
                }
            }
        }
        SlotNotifySubscription::where('telegram_bot_id', $bot->id)->where('raffle_id', $raffleId)->delete();

        $activeRaffle = Raffle::resolveActiveForBot($bot->id);
        $reservations = [];
        if ($activeRaffle) {
            $reservationOrders = Order::where('raffle_id', $activeRaffle->id)
                ->where('status', Order::STATUS_RESERVED)
                ->with('botUser')
                ->orderBy('reserved_until')
                ->get();
            foreach ($reservationOrders as $o) {
                $user = $o->botUser;
                $reservedUntil = $o->reserved_until ? $o->reserved_until->timezone('Europe/Moscow') : null;
                $minutesLeft = $reservedUntil && $reservedUntil->isFuture() ? (int) $reservedUntil->diffInMinutes(now()) : 0;
                $reservations[] = [
                    'order_id' => $o->id,
                    'bot_user_id' => $o->bot_user_id,
                    'user_name' => $user ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) : '—',
                    'username' => $user?->username ?? null,
                    'quantity' => $o->quantity,
                    'reserved_until' => $reservedUntil ? $reservedUntil->toIso8601String() : null,
                    'minutes_left' => $minutesLeft,
                ];
            }
        }

        return response()->json([
            'message' => 'Бронь отменена. Пользователь и подписчики уведомлены.',
            'reservations' => $reservations,
            'tickets_stats' => Ticket::getStats($bot->id, $activeRaffle?->id),
        ]);
    }

    /**
     * Подписанный URL для QR-кода (используется в show/uploadQr).
     */
    private function getQrImageUrlForApi(int $botId, BotSettings $settings): ?string
    {
        if (!$settings->qr_image_path) {
            return null;
        }
        return URL::temporarySignedRoute(
            'api.raffle-settings.qr-image',
            now()->addMinutes(60),
            ['id' => $botId]
        );
    }

    /**
     * Отдать файл QR-кода по подписанному URL (маршрут без auth:sanctum).
     */
    public function qrImage(Request $request, int $id): Response
    {
        $settings = BotSettings::where('telegram_bot_id', $id)->first();
        if (!$settings || !$settings->qr_image_path) {
            abort(404);
        }
        $path = $settings->getQrImageFullPath();
        if (!$path || !is_file($path) || !is_readable($path)) {
            abort(404);
        }
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
        return response()->file($path, ['Content-Type' => $mime]);
    }
}
