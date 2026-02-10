<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotSettings;
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
        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $settings = BotSettings::getOrCreate($bot->id);
        
        // Добавляем статистику
        $ticketsStats = Ticket::getStats($bot->id);

        return response()->json([
            'settings' => $settings,
            'tickets_stats' => $ticketsStats,
            'qr_image_url' => $settings->getQrImageUrl(),
            'default_messages' => BotSettings::DEFAULTS,
        ]);
    }

    /**
     * Обновить настройки розыгрыша
     */
    public function update(Request $request, int $id): JsonResponse
    {
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

        // Обновляем только переданные поля
        $fillable = array_filter($validated, fn($v) => $v !== null);
        $settings->fill($fillable);
        $settings->save();

        // Если изменилось количество мест — уменьшаем лишние или добавляем недостающие
        if (isset($validated['total_slots'])) {
            $newTotal = (int) $validated['total_slots'];
            $raffleId = $settings->current_raffle_id;
            $currentCount = Ticket::where('telegram_bot_id', $bot->id)
                ->when($raffleId !== null, fn($q) => $q->where('raffle_id', $raffleId), fn($q) => $q->whereNull('raffle_id'))
                ->count();
            if ($newTotal < $currentCount) {
                Ticket::reduceToTotal($bot->id, $newTotal, $raffleId);
            }
            Ticket::initializeForBot($bot->id, $newTotal, $raffleId);
            if ($raffleId) {
                Raffle::where('id', $raffleId)->update(['total_slots' => $newTotal]);
            }
        }

        return response()->json([
            'message' => 'Настройки сохранены',
            'settings' => $settings->fresh(),
            'tickets_stats' => Ticket::getStats($bot->id),
        ]);
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
        
        Ticket::initializeForBot($bot->id, $settings->total_slots);

        return response()->json([
            'message' => 'Номерки инициализированы',
            'tickets_stats' => Ticket::getStats($bot->id),
        ]);
    }
}
