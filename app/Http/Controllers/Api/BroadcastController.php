<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotUser;
use App\Models\Broadcast;
use App\Models\TelegramBot;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BroadcastController extends Controller
{
    /**
     * Список рассылок (история)
     */
    public function index(Request $request): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)->first();
        if (!$bot) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = Broadcast::where('telegram_bot_id', $bot->id)
            ->orderByDesc('created_at');

        $perPage = (int) $request->get('per_page', 15);
        $broadcasts = $query->paginate($perPage);

        $broadcasts->getCollection()->transform(function ($b) {
            return [
                'id' => $b->id,
                'type' => $b->type,
                'type_label' => $b->getTypeLabel(),
                'message_text' => $b->message_text ? \Str::limit($b->message_text, 80) : null,
                'has_file' => !empty($b->file_path),
                'recipients_type' => $b->recipients_type,
                'recipients_count' => $b->recipients_count,
                'success_count' => $b->success_count,
                'failed_count' => $b->failed_count,
                'created_at' => $b->created_at->toIso8601String(),
            ];
        });

        return response()->json($broadcasts);
    }

    /**
     * Отправить рассылку
     */
    public function store(Request $request): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)->first();
        if (!$bot) {
            return response()->json(['message' => 'Бот не найден'], 404);
        }

        $validated = $request->validate([
            'type' => 'required|in:text,photo,video,photo_text,video_text',
            'message_text' => 'nullable|string|max:4096',
            'recipients_type' => 'required|in:all,selected',
            'recipient_ids' => 'required_if:recipients_type,selected|array',
            'recipient_ids.*' => 'integer|exists:bot_users,id',
        ]);

        $type = $validated['type'];
        $messageText = $validated['message_text'] ?? '';
        $recipientsType = $validated['recipients_type'];
        $recipientIds = $validated['recipient_ids'] ?? [];

        $needFile = in_array($type, ['photo', 'video', 'photo_text', 'video_text'], true);
        $filePath = null;

        if ($needFile) {
            $request->validate([
                'file' => 'required|file|max:51200', // 50 MB
            ]);
            $file = $request->file('file');
            $ext = strtolower($file->getClientOriginalExtension());
            if (in_array($type, ['photo', 'photo_text'], true)) {
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                    return response()->json(['message' => 'Для фото допустимы: JPG, PNG, GIF, WebP'], 422);
                }
            } else {
                if (!in_array($ext, ['mp4', 'mov', 'avi', 'webm'], true)) {
                    return response()->json(['message' => 'Для видео допустимы: MP4, MOV, AVI, WebM'], 422);
                }
            }
            $filePath = $file->store('broadcasts', 'local');
        } elseif ($type === 'text' && empty(trim($messageText))) {
            return response()->json(['message' => 'Введите текст сообщения'], 422);
        }

        $query = BotUser::where('telegram_bot_id', $bot->id)->where('is_blocked', false);
        if ($recipientsType === Broadcast::RECIPIENTS_SELECTED && !empty($recipientIds)) {
            $query->whereIn('id', $recipientIds);
        }
        $users = $query->get();
        $recipientsCount = $users->count();

        if ($recipientsCount === 0) {
            if ($filePath) {
                Storage::disk('local')->delete($filePath);
            }
            return response()->json(['message' => 'Нет получателей для рассылки'], 422);
        }

        $telegram = new TelegramService($bot);
        $successCount = 0;
        $failedTelegramIds = [];

        foreach ($users as $user) {
            $chatId = $user->telegram_user_id;
            try {
                $ok = false;
                if ($type === 'text') {
                    $result = $telegram->sendMessage($chatId, $messageText);
                    $ok = $result && ($result['ok'] ?? false);
                } elseif (in_array($type, ['photo', 'photo_text'], true)) {
                    $caption = in_array($type, ['photo_text'], true) ? $messageText : null;
                    $path = Storage::disk('local')->path($filePath);
                    $result = $telegram->sendPhoto($chatId, $path, $caption);
                    $ok = $result && ($result['ok'] ?? false);
                } else {
                    $caption = in_array($type, ['video_text'], true) ? $messageText : null;
                    $path = Storage::disk('local')->path($filePath);
                    $result = $telegram->sendVideo($chatId, $path, $caption);
                    $ok = $result && ($result['ok'] ?? false);
                }
                if ($ok) {
                    $successCount++;
                } else {
                    $failedTelegramIds[] = $chatId;
                }
            } catch (\Exception $e) {
                Log::warning('Broadcast send failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
                $failedTelegramIds[] = $chatId;
            }
        }

        $broadcast = Broadcast::create([
            'telegram_bot_id' => $bot->id,
            'user_id' => $request->user()->id,
            'type' => $type,
            'message_text' => $messageText ?: null,
            'file_path' => $filePath,
            'recipients_type' => $recipientsType,
            'recipients_count' => $recipientsCount,
            'success_count' => $successCount,
            'failed_count' => count($failedTelegramIds),
            'failed_telegram_ids' => $failedTelegramIds ?: null,
        ]);

        return response()->json([
            'message' => 'Рассылка завершена',
            'broadcast' => [
                'id' => $broadcast->id,
                'type' => $broadcast->type,
                'type_label' => $broadcast->getTypeLabel(),
                'recipients_count' => $broadcast->recipients_count,
                'success_count' => $broadcast->success_count,
                'failed_count' => $broadcast->failed_count,
                'created_at' => $broadcast->created_at->toIso8601String(),
            ],
        ]);
    }
}
