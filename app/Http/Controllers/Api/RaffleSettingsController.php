<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotSettings;
use App\Models\Order;
use App\Models\Raffle;
use App\Models\TelegramBot;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

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
                'qr_image_url' => $settings->getQrImageUrl(),
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
            'qr_image_url' => $settings->getQrImageUrl(),
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
}
