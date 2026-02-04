<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotUser;
use App\Models\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BotUserController extends Controller
{
    /**
     * Получить список пользователей бота
     */
    public function index(Request $request): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)->first();
        
        if (!$bot) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = BotUser::withCount(['tickets', 'checks'])
            ->where('telegram_bot_id', $bot->id)
            ->orderBy('created_at', 'desc');

        // Фильтр по роли
        if ($request->has('role') && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        // Фильтр по статусу
        if ($request->has('status')) {
            if ($request->status === 'blocked') {
                $query->where('is_blocked', true);
            } elseif ($request->status === 'active') {
                $query->where('is_blocked', false);
            }
        }

        // Поиск
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('telegram_user_id', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate($request->get('per_page', 20));

        // Добавляем расшифрованные данные (только для админки)
        $users->getCollection()->transform(function ($user) {
            return [
                'id' => $user->id,
                'telegram_user_id' => $user->telegram_user_id,
                'username' => $user->username,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'display_name' => $user->getDisplayName(),
                'role' => $user->role,
                'fsm_state' => $user->fsm_state,
                'is_blocked' => $user->is_blocked,
                'has_personal_data' => $user->hasAllPersonalData(),
                'tickets_count' => $user->tickets_count,
                'checks_count' => $user->checks_count,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ];
        });

        return response()->json($users);
    }

    /**
     * Получить детали пользователя
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)->first();
        
        if (!$bot) {
            return response()->json(['error' => 'Бот не найден'], 404);
        }

        $user = BotUser::with(['tickets', 'checks', 'adminRequests'])
            ->where('telegram_bot_id', $bot->id)
            ->where('id', $id)
            ->firstOrFail();

        // Возвращаем данные с расшифровкой
        return response()->json([
            'id' => $user->id,
            'telegram_user_id' => $user->telegram_user_id,
            'username' => $user->username,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'display_name' => $user->getDisplayName(),
            'role' => $user->role,
            'fsm_state' => $user->fsm_state,
            'is_blocked' => $user->is_blocked,
            // Расшифрованные персональные данные
            'fio' => $user->fio,
            'phone' => $user->phone,
            'inn' => $user->inn,
            // Номерки
            'tickets' => $user->tickets->pluck('number')->sort()->values(),
            'tickets_count' => $user->tickets->count(),
            // Чеки
            'checks' => $user->checks->map(fn($c) => [
                'id' => $c->id,
                'amount' => $c->amount,
                'review_status' => $c->review_status,
                'created_at' => $c->created_at,
            ]),
            'checks_count' => $user->checks->count(),
            // Запросы на роль
            'admin_requests' => $user->adminRequests,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ]);
    }
}
