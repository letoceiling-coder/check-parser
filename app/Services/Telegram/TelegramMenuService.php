<?php

namespace App\Services\Telegram;

use App\Models\BotSettings;
use App\Models\BotUser;
use App\Models\Raffle;
use App\Models\TelegramBot;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramMenuService
{
    // Тексты кнопок постоянного меню
    public const BTN_HOME = '🏠 Главная';
    public const BTN_ABOUT = 'ℹ️ О розыгрыше';
    public const BTN_MY_TICKETS = '🎫 Мои номерки';
    public const BTN_SUPPORT = '💬 Поддержка';

    // Inline кнопки для сценариев
    public const BTN_BACK = '◀️ Назад';
    public const BTN_CANCEL = '❌ Отмена';
    public const BTN_PARTICIPATE = '✅ Участвовать';
    public const BTN_CONFIRM = '✅ Подтвердить';
    public const BTN_EDIT = '✏️ Изменить';

    private TelegramBot $bot;
    private ?BotSettings $settings;

    public function __construct(TelegramBot $bot)
    {
        $this->bot = $bot;
        $this->settings = BotSettings::where('telegram_bot_id', $bot->id)->first();
    }

    /**
     * Получить постоянную Reply Keyboard (статический метод для использования без экземпляра)
     * Клавиатура отображается ВСЕГДА (is_persistent: true).
     */
    public static function getReplyKeyboardArray(): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => self::BTN_HOME],
                    ['text' => self::BTN_ABOUT],
                ],
                [
                    ['text' => self::BTN_MY_TICKETS],
                    ['text' => self::BTN_SUPPORT],
                ],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /**
     * Получить постоянную Reply Keyboard (экземплярный метод)
     */
    public function getReplyKeyboard(): array
    {
        return self::getReplyKeyboardArray();
    }

    /**
     * Отправить сообщение с постоянной клавиатурой
     */
    public function sendMessageWithMenu(int $chatId, string $text, ?array $inlineKeyboard = null): ?array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($this->getReplyKeyboard()),
        ];

        // Если есть inline кнопки, добавляем их вместо reply keyboard
        if ($inlineKeyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $inlineKeyboard,
            ]);
        }

        return $this->sendRequest('sendMessage', $params);
    }

    /**
     * Отправить сообщение только с inline кнопками (без изменения reply keyboard)
     */
    public function sendMessageWithInline(int $chatId, string $text, array $inlineKeyboard): ?array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $inlineKeyboard,
            ]),
        ];

        return $this->sendRequest('sendMessage', $params);
    }

    /**
     * Редактировать сообщение (для переходов внутри сценария)
     */
    public function editMessage(int $chatId, int $messageId, string $text, ?array $inlineKeyboard = null): ?array
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($inlineKeyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $inlineKeyboard,
            ]);
        }

        return $this->sendRequest('editMessageText', $params);
    }

    /**
     * Удалить сообщение
     */
    public function deleteMessage(int $chatId, int $messageId): bool
    {
        $result = $this->sendRequest('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);

        return $result !== null;
    }

    /**
     * Отправить фото с постоянной клавиатурой
     */
    public function sendPhotoWithMenu(int $chatId, string $photoPath, ?string $caption = null): ?array
    {
        $params = [
            'chat_id' => $chatId,
            'reply_markup' => json_encode($this->getReplyKeyboard()),
        ];

        if ($caption) {
            $params['caption'] = $caption;
            $params['parse_mode'] = 'HTML';
        }

        // Отправляем файл
        $fullPath = storage_path('app/public/' . $photoPath);
        
        if (!file_exists($fullPath)) {
            Log::error('Photo not found', ['path' => $fullPath]);
            return null;
        }

        try {
            $response = Http::attach(
                'photo',
                file_get_contents($fullPath),
                basename($fullPath)
            )->post("https://api.telegram.org/bot{$this->bot->token}/sendPhoto", $params);

            if ($response->successful()) {
                return $response->json()['result'] ?? null;
            }

            Log::error('Failed to send photo', ['response' => $response->body()]);
            return null;
        } catch (\Exception $e) {
            Log::error('Error sending photo: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Получить inline кнопки для сценария с кнопками Назад и Отмена
     */
    public function getScenarioKeyboard(string $backCallback, bool $showBack = true): array
    {
        $buttons = [];

        if ($showBack) {
            $buttons[] = ['text' => self::BTN_BACK, 'callback_data' => $backCallback];
        }

        $buttons[] = ['text' => self::BTN_CANCEL, 'callback_data' => 'cancel'];

        return [$buttons];
    }

    /**
     * Получить inline кнопки для подтверждения данных
     */
    public function getConfirmKeyboard(): array
    {
        return [
            [
                ['text' => self::BTN_CONFIRM, 'callback_data' => 'confirm_data'],
                ['text' => self::BTN_EDIT, 'callback_data' => 'edit_data'],
            ],
            [
                ['text' => self::BTN_CANCEL, 'callback_data' => 'cancel'],
            ],
        ];
    }

    /**
     * Получить inline кнопки для приветственного экрана
     */
    public function getWelcomeKeyboard(bool $hasSlots = true): array
    {
        if ($hasSlots) {
            return [
                [
                    ['text' => self::BTN_PARTICIPATE, 'callback_data' => 'participate'],
                ],
            ];
        }

        return [
            [
                ['text' => '📢 Перейти в канал', 'url' => 'https://t.me/channel'],
            ],
        ];
    }

    /**
     * Обработать нажатие кнопки "О розыгрыше"
     */
    public function handleAboutRaffle(int $chatId, BotUser $botUser): void
    {
        $raffle = $this->settings?->getActiveRaffle();
        $availableSlots = $this->settings ? $this->settings->getAvailableSlotsCount() : 0;
        $totalSlots = $raffle ? (int) $raffle->total_slots : ($this->settings->total_slots ?? 500);
        $price = $raffle ? (float) $raffle->slot_price : ($this->settings->slot_price ?? 10000);
        $prize = $raffle?->prize_description ?? $this->settings->prize_description ?? 'Главный приз';
        $raffleInfo = $raffle?->raffle_info ?? $this->settings->raffle_info ?? '';

        $message = $this->settings->getMessage('about_raffle', [
            'prize' => $prize,
            'price' => number_format($price, 0, '', ' '),
            'total_slots' => $totalSlots,
            'available_slots' => $availableSlots,
            'raffle_info' => $raffleInfo,
        ]);

        $this->sendMessageWithMenu($chatId, $message);
    }

    /**
     * Обработать нажатие кнопки "Мои номерки"
     */
    public function handleMyTickets(int $chatId, BotUser $botUser): void
    {
        $tickets = Ticket::where('bot_user_id', $botUser->id)
            ->orderBy('number')
            ->pluck('number')
            ->toArray();

        if (empty($tickets)) {
            $message = $this->settings->getMessage('no_tickets', []);
            $this->sendMessageWithMenu($chatId, $message);
            return;
        }

        $ticketsList = implode(', ', array_map(fn($n) => "№{$n}", $tickets));
        
        $message = $this->settings->getMessage('my_tickets', [
            'tickets' => $ticketsList,
            'count' => count($tickets),
        ]);

        $this->sendMessageWithMenu($chatId, $message);
    }

    /**
     * Обработать нажатие кнопки "Поддержка"
     */
    public function handleSupport(int $chatId): void
    {
        $supportContact = $this->settings->support_contact ?? '@support';

        $message = $this->settings->getMessage('support', [
            'support_contact' => $supportContact,
        ]);

        $this->sendMessageWithMenu($chatId, $message);
    }

    /**
     * Отправить запрос к Telegram API
     */
    private function sendRequest(string $method, array $params): ?array
    {
        try {
            $response = Http::post("https://api.telegram.org/bot{$this->bot->token}/{$method}", $params);

            if ($response->successful()) {
                $data = $response->json();
                if ($data['ok'] ?? false) {
                    return $data['result'] ?? null;
                }
            }

            Log::error("Telegram API error: {$method}", [
                'params' => $params,
                'response' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error("Telegram API exception: {$method}", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Ответить на callback query
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): void
    {
        $params = [
            'callback_query_id' => $callbackQueryId,
            'show_alert' => $showAlert,
        ];

        if ($text) {
            $params['text'] = $text;
        }

        $this->sendRequest('answerCallbackQuery', $params);
    }
}
