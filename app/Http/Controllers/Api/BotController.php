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

        return response()->json($bot);
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
            'webhook_url' => 'required|url',
        ]);

        $bot = TelegramBot::create([
            'user_id' => $request->user()->id,
            'token' => $request->token,
            'webhook_url' => $request->webhook_url,
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
            'webhook_url' => 'required|url',
        ]);

        $bot->update([
            'token' => $request->token,
            'webhook_url' => $request->webhook_url,
        ]);

        // Re-register webhook
        $this->registerWebhook($bot);

        return response()->json($bot);
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
            // Send test message using Telegram API
            $response = Http::post("https://api.telegram.org/bot{$bot->token}/sendMessage", [
                'chat_id' => $request->user()->id, // You might want to get chat_id from bot
                'text' => 'Test message from webhook!',
            ]);

            if ($response->successful()) {
                return response()->json([
                    'message' => 'Webhook test successful! Check your Telegram bot.',
                    'response' => $response->json(),
                ]);
            }

            return response()->json([
                'message' => 'Webhook test failed',
                'error' => $response->json(),
            ], 400);
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
