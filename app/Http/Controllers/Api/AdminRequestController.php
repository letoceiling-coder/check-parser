<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminRequest;
use App\Models\AdminActionLog;
use App\Models\TelegramBot;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AdminRequestController extends Controller
{
    /**
     * Получить список запросов на роль админа
     */
    public function index(Request $request): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)->first();
        
        if (!$bot) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = AdminRequest::with(['botUser', 'reviewer'])
            ->where('telegram_bot_id', $bot->id)
            ->orderBy('created_at', 'desc');

        // Фильтр по статусу
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $requests = $query->paginate($request->get('per_page', 20));

        return response()->json($requests);
    }

    /**
     * Одобрить запрос на роль админа
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)->first();
        
        if (!$bot) {
            return response()->json(['error' => 'Бот не найден'], 404);
        }

        $adminRequest = AdminRequest::where('telegram_bot_id', $bot->id)
            ->where('id', $id)
            ->firstOrFail();

        if (!$adminRequest->isPending()) {
            return response()->json(['error' => 'Запрос уже обработан'], 400);
        }

        $request->validate([
            'comment' => 'nullable|string|max:500',
        ]);

        // Одобряем запрос
        $adminRequest->approve($request->user()->id, $request->comment);

        // Логируем
        AdminActionLog::logAdminRequestApproved($adminRequest, $request->user()->id);

        // Уведомляем пользователя
        $this->notifyUser($bot, $adminRequest, true);

        return response()->json([
            'message' => 'Запрос одобрен',
            'request' => $adminRequest->fresh()->load(['botUser', 'reviewer']),
        ]);
    }

    /**
     * Отклонить запрос на роль админа
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)->first();
        
        if (!$bot) {
            return response()->json(['error' => 'Бот не найден'], 404);
        }

        $adminRequest = AdminRequest::where('telegram_bot_id', $bot->id)
            ->where('id', $id)
            ->firstOrFail();

        if (!$adminRequest->isPending()) {
            return response()->json(['error' => 'Запрос уже обработан'], 400);
        }

        $request->validate([
            'comment' => 'nullable|string|max:500',
        ]);

        // Отклоняем запрос
        $adminRequest->reject($request->user()->id, $request->comment);

        // Логируем
        AdminActionLog::logAdminRequestRejected($adminRequest, $request->user()->id, $request->comment);

        // Уведомляем пользователя
        $this->notifyUser($bot, $adminRequest, false);

        return response()->json([
            'message' => 'Запрос отклонён',
            'request' => $adminRequest->fresh()->load(['botUser', 'reviewer']),
        ]);
    }

    /**
     * Уведомить пользователя о результате
     */
    private function notifyUser(TelegramBot $bot, AdminRequest $adminRequest, bool $approved): void
    {
        $telegram = new TelegramService($bot);
        $botUser = $adminRequest->botUser;
        $settings = $bot->getOrCreateSettings();

        if ($approved) {
            $message = $settings->getMessage('admin_request_approved');
        } else {
            $message = $settings->getMessage('admin_request_rejected', [
                'reason' => $adminRequest->admin_comment ?? '',
            ]);
        }

        $telegram->sendMessage($botUser->telegram_user_id, $message);
    }
}
