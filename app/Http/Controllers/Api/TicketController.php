<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TicketController extends Controller
{
    /**
     * Получить список номерков
     */
    public function index(Request $request): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)->first();
        
        if (!$bot) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = Ticket::with(['botUser', 'check'])
            ->where('telegram_bot_id', $bot->id)
            ->orderBy('number', 'asc');

        // Фильтр по статусу
        if ($request->has('status')) {
            if ($request->status === 'issued') {
                $query->issued();
            } elseif ($request->status === 'available') {
                $query->available();
            }
        }

        // Поиск по номеру или пользователю
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('botUser', function ($q) use ($search) {
                        $q->where('username', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('telegram_user_id', 'like', "%{$search}%");
                    });
            });
        }

        $tickets = $query->paginate($request->get('per_page', 50));

        return response()->json($tickets);
    }

    /**
     * Получить статистику по номеркам
     */
    public function stats(Request $request): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)->first();
        
        if (!$bot) {
            return response()->json([
                'total' => 0,
                'issued' => 0,
                'available' => 0,
                'percentage_issued' => 0,
            ]);
        }

        $stats = Ticket::getStats($bot->id);

        // Дополнительная статистика
        $stats['recent_issued'] = Ticket::where('telegram_bot_id', $bot->id)
            ->whereNotNull('issued_at')
            ->where('issued_at', '>=', now()->subDays(7))
            ->count();

        $stats['top_users'] = Ticket::where('telegram_bot_id', $bot->id)
            ->whereNotNull('bot_user_id')
            ->selectRaw('bot_user_id, count(*) as tickets_count')
            ->groupBy('bot_user_id')
            ->orderByDesc('tickets_count')
            ->limit(10)
            ->with('botUser')
            ->get();

        return response()->json($stats);
    }
}
