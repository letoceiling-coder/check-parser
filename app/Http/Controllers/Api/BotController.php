<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use LetoceilingCoder\Telegram\Bot;
use LetoceilingCoder\Telegram\Exceptions\TelegramException;
use LetoceilingCoder\Telegram\Exceptions\TelegramValidationException;

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

        $telegramBot = TelegramBot::create([
            'user_id' => $request->user()->id,
            'token' => $request->token,
            'webhook_url' => $webhookUrl,
            'is_active' => true,
        ]);

        // Register webhook automatically using package
        $this->registerWebhook($telegramBot);

        return response()->json($telegramBot, 201);
    }

    /**
     * Update bot
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $telegramBot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $request->validate([
            'token' => 'required|string',
        ]);

        // Generate webhook URL automatically
        $webhookUrl = config('app.url') . '/api/telegram/webhook';

        $telegramBot->update([
            'token' => $request->token,
            'webhook_url' => $webhookUrl,
        ]);

        // Re-register webhook
        $this->registerWebhook($telegramBot);

        return response()->json($telegramBot);
    }

    /**
     * Update bot settings (welcome message, etc.)
     */
    public function updateSettings(Request $request, int $id): JsonResponse
    {
        $telegramBot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $request->validate([
            'welcome_message' => 'nullable|string|max:4000',
        ]);

        $telegramBot->update([
            'welcome_message' => $request->welcome_message,
        ]);

        return response()->json([
            'message' => 'Настройки сохранены',
            'welcome_message' => $telegramBot->welcome_message,
            'welcome_message_display' => $telegramBot->getWelcomeMessageText(),
        ]);
    }

    /**
     * Get bot description from Telegram API using package
     */
    public function getDescription(Request $request, int $id): JsonResponse
    {
        $telegramBot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        try {
            $bot = new Bot($telegramBot->token);
            
            // Получаем описание бота через пакет
            $descResponse = $bot->getMyDescription();
            $shortDescResponse = $bot->getMyShortDescription();

            $description = '';
            $shortDescription = '';

            if (isset($descResponse['ok']) && $descResponse['ok']) {
                $description = $descResponse['result']['description'] ?? '';
            }

            if (isset($shortDescResponse['ok']) && $shortDescResponse['ok']) {
                $shortDescription = $shortDescResponse['result']['short_description'] ?? '';
            }

            return response()->json([
                'description' => $description,
                'short_description' => $shortDescription,
            ]);
        } catch (TelegramException $e) {
            Log::error('Error getting bot description: ' . $e->getMessage());
            return response()->json([
                'error' => 'Ошибка получения описания: ' . $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error getting bot description: ' . $e->getMessage());
            return response()->json([
                'error' => 'Ошибка получения описания: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update bot description via Telegram API using package
     */
    public function updateDescription(Request $request, int $id): JsonResponse
    {
        $telegramBot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $request->validate([
            'description' => 'nullable|string|max:512',
            'short_description' => 'nullable|string|max:120',
        ]);

        $errors = [];
        $success = [];

        try {
            $bot = new Bot($telegramBot->token);

            // Устанавливаем описание (показывается в пустом чате) через пакет
            if ($request->has('description')) {
                try {
                    $response = $bot->setMyDescription($request->description ?: null);
                    
                    if (isset($response['ok']) && $response['ok']) {
                        $success[] = 'Описание бота обновлено';
                    } else {
                        $errors[] = 'Ошибка описания: ' . ($response['description'] ?? 'Unknown');
                    }
                } catch (TelegramValidationException $e) {
                    $errors[] = 'Ошибка валидации описания: ' . $e->getMessage();
                }
            }

            // Устанавливаем краткое описание (в профиле) через пакет
            if ($request->has('short_description')) {
                try {
                    $response = $bot->setMyShortDescription($request->short_description ?: null);
                    
                    if (isset($response['ok']) && $response['ok']) {
                        $success[] = 'Краткое описание обновлено';
                    } else {
                        $errors[] = 'Ошибка краткого описания: ' . ($response['description'] ?? 'Unknown');
                    }
                } catch (TelegramValidationException $e) {
                    $errors[] = 'Ошибка валидации краткого описания: ' . $e->getMessage();
                }
            }

            if (!empty($errors)) {
                return response()->json([
                    'message' => implode('. ', $errors),
                    'success' => $success,
                ], 400);
            }

            return response()->json([
                'message' => implode('. ', $success) ?: 'Описание обновлено',
            ]);
        } catch (TelegramException $e) {
            Log::error('Error updating bot description: ' . $e->getMessage());
            return response()->json([
                'error' => 'Ошибка Telegram: ' . $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error updating bot description: ' . $e->getMessage());
            return response()->json([
                'error' => 'Ошибка: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test webhook using package
     */
    public function testWebhook(Request $request, int $id): JsonResponse
    {
        $telegramBot = TelegramBot::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        try {
            $bot = new Bot($telegramBot->token);
            
            // Получаем информацию о боте через пакет
            $botInfoResponse = $bot->getMe();

            if (!isset($botInfoResponse['ok']) || !$botInfoResponse['ok']) {
                return response()->json([
                    'message' => 'Invalid bot token or bot not found',
                    'error' => $botInfoResponse['description'] ?? 'Unknown error',
                ], 400);
            }

            $botUsername = $botInfoResponse['result']['username'] ?? 'unknown';

            // Try to send test message to the user who created the bot
            $chatId = $request->user()->id;
            
            try {
                $response = $bot->sendMessage($chatId, '✅ Тестовое сообщение от бота! Webhook работает корректно.');

                if (isset($response['ok']) && $response['ok']) {
                    return response()->json([
                        'message' => 'Webhook test successful! Check your Telegram bot (@' . $botUsername . ').',
                        'bot_username' => $botUsername,
                        'response' => $response,
                    ]);
                }

                $errorDescription = $response['description'] ?? 'Unknown error';
            } catch (TelegramException $e) {
                $errorDescription = $e->getMessage();
            }
            
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
            ], 400);
        } catch (TelegramException $e) {
            Log::error('Webhook test error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Telegram error: ' . $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            Log::error('Webhook test error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error testing webhook: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Register webhook with Telegram using package
     */
    private function registerWebhook(TelegramBot $telegramBot): void
    {
        try {
            $bot = new Bot($telegramBot->token);
            $response = $bot->setWebhook($telegramBot->webhook_url);

            if (isset($response['ok']) && $response['ok']) {
                Log::info("Webhook registered for bot {$telegramBot->id}: {$telegramBot->webhook_url}");
            } else {
                Log::error("Failed to register webhook for bot {$telegramBot->id}: " . json_encode($response));
            }
        } catch (\Exception $e) {
            Log::error("Error registering webhook for bot {$telegramBot->id}: " . $e->getMessage());
        }
    }
}
