<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminActionLog;
use App\Models\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdminActionLogController extends Controller
{
    /**
     * Получить лог действий администраторов
     */
    public function index(Request $request): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)->first();
        
        if (!$bot) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = AdminActionLog::with('adminUser')
            ->where('telegram_bot_id', $bot->id)
            ->orderBy('created_at', 'desc');

        // Фильтр по типу действия
        if ($request->has('action_type') && $request->action_type !== 'all') {
            $query->where('action_type', $request->action_type);
        }

        // Фильтр по дате
        if ($request->has('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $logs = $query->paginate($request->get('per_page', 50));

        return response()->json($logs);
    }
}
