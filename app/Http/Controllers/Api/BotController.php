<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BotController extends Controller
{
    /**
     * Get bot for authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)->first();

        if (!$bot) {
            return response()->json(['message' => 'Bot not found'], 404);
        }

        // Добавляем дефолтное сообщение если не установлено
        $botData = $bot->toArray();
        $botData['welcome_message'] = $bot->welcome_message;
        $botData['welcome_message_display'] = $bot->getWelcomeMessageText();
        $botData['default_welcome_message'] = TelegramBot::DEFAULT_WELCOME_MESSAGE;

        return response()->json($botData);
    }

    /**
     * Create bot
     */
    public function store(Request $request): JsonResponse
    {
        // Check if user already has a bot
        $existingBot = TelegramBot::where('user_id', $request->user()->id)->first();
        if ($existingBot) {
            return response()->json([
                'message' => 'You can only create one bot'
            ], 422);
        }

        $request->validate([
            'token' => 'required|string',
        ]);

        // Generate webhook URL automatically
        $webhookUrl = config('app.url') . '/api/telegram/webhook';

        $bot = TelegramBot::create([
            'user_id' => $request->user()->id,
            'token' => $request->token,
            'webhook_url' => $webhookUrl,
            'is_active' => true,
        ]);

        // Register webhook automatically
        $this->registerWebhook($bot);

        return response()->json($bot, 201);
    }

    /**
     * Update bot
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $request->validate([
            'token' => 'required|string',
        ]);

        // Generate webhook URL automatically
        $webhookUrl = config('app.url') . '/api/telegram/webhook';

        $bot->update([
            'token' => $request->token,
            'webhook_url' => $webhookUrl,
        ]);

        // Re-register webhook
        $this->registerWebhook($bot);

        return response()->json($bot);
    }

    /**
     * Update bot settings (welcome message, etc.)
     */
    public function updateSettings(Request $request, int $id): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $request->validate([
            'welcome_message' => 'nullable|string|max:4000',
        ]);

        $bot->update([
            'welcome_message' => $request->welcome_message,
        ]);

        return response()->json([
            'message' => 'Настройки сохранены',
            'welcome_message' => $bot->welcome_message,
            'welcome_message_display' => $bot->getWelcomeMessageText(),
        ]);
    }

    /**
     * Test webhook
     */
    public function testWebhook(Request $request, int $id): JsonResponse
    {
        $bot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        try {
            // First, get bot info to verify token is valid
            $botInfoResponse = Http::timeout(10)
                ->get("https://api.telegram.org/bot{$bot->token}/getMe");

            if (!$botInfoResponse->successful()) {
                $errorData = $botInfoResponse->json();
                return response()->json([
                    'message' => 'Invalid bot token or bot not found',
                    'error' => $errorData['description'] ?? 'Unknown error',
                ], 400);
            }

            $botInfo = $botInfoResponse->json();
            $botUsername = $botInfo['result']['username'] ?? 'unknown';

            // Try to send test message to the user who created the bot
            // Note: This will only work if the user has started a conversation with the bot
            $chatId = $request->user()->id;
            
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$bot->token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => '✅ Тестовое сообщение от бота! Webhook работает корректно.',
                ]);

            if ($response->successful()) {
                return response()->json([
                    'message' => 'Webhook test successful! Check your Telegram bot (@' . $botUsername . ').',
                    'bot_username' => $botUsername,
                    'response' => $response->json(),
                ]);
            }

            // If sending message failed, check the error
            $errorData = $response->json();
            $errorDescription = $errorData['description'] ?? 'Unknown error';
            
            // Common error: user hasn't started conversation with bot
            if (str_contains($errorDescription, 'chat not found') || str_contains($errorDescription, 'bot was blocked')) {
                return response()->json([
                    'message' => 'Для тестирования webhook необходимо начать диалог с ботом. Найдите бота @' . $botUsername . ' в Telegram и отправьте ему команду /start.',
                    'bot_username' => $botUsername,
                    'error' => $errorDescription,
                ], 400);
            }

            return response()->json([
                'message' => 'Webhook test failed: ' . $errorDescription,
                'bot_username' => $botUsername,
                'error' => $errorData,
            ], 400);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Webhook test connection error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Connection error: Unable to reach Telegram API',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            Log::error('Webhook test error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error testing webhook: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Register webhook with Telegram
     */
    private function registerWebhook(TelegramBot $bot): void
    {
        try {
            $response = Http::post("https://api.telegram.org/bot{$bot->token}/setWebhook", [
                'url' => $bot->webhook_url,
            ]);

            if ($response->successful()) {
                Log::info("Webhook registered for bot {$bot->id}: {$bot->webhook_url}");
            } else {
                Log::error("Failed to register webhook for bot {$bot->id}: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("Error registering webhook for bot {$bot->id}: " . $e->getMessage());
        }
    }
}
