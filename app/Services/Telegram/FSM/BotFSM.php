<?php

namespace App\Services\Telegram\FSM;

use App\Models\BotUser;
use App\Models\BotSettings;
use App\Models\TelegramBot;
use Illuminate\Support\Facades\Log;

/**
 * Finite State Machine для Telegram бота
 * Управляет состояниями пользователя и переходами между ними
 */
class BotFSM
{
    // ==========================================
    // Константы состояний
    // ==========================================
    
    public const STATE_IDLE = 'IDLE';
    public const STATE_WELCOME = 'WELCOME';
    public const STATE_WAIT_FIO = 'WAIT_FIO';
    public const STATE_WAIT_PHONE = 'WAIT_PHONE';
    public const STATE_WAIT_INN = 'WAIT_INN';
    public const STATE_CONFIRM_DATA = 'CONFIRM_DATA';
    public const STATE_SHOW_QR = 'SHOW_QR';
    public const STATE_WAIT_CHECK = 'WAIT_CHECK';
    public const STATE_PENDING_REVIEW = 'PENDING_REVIEW';
    public const STATE_APPROVED = 'APPROVED';
    public const STATE_REJECTED = 'REJECTED';
    
    // Состояния для редактирования (админ в боте)
    public const STATE_ADMIN_EDIT_AMOUNT = 'ADMIN_EDIT_AMOUNT';
    public const STATE_ADMIN_CONFIRM_EDIT = 'ADMIN_CONFIRM_EDIT';
    
    // Режим тестирования
    public const STATE_TEST_MODE = 'TEST_MODE';

    // ==========================================
    // Callback data префиксы
    // ==========================================
    
    public const CB_PARTICIPATE = 'participate';
    public const CB_CONFIRM_DATA = 'confirm_data';
    public const CB_EDIT_DATA = 'edit_data';
    public const CB_BACK = 'back';
    public const CB_CANCEL = 'cancel';
    public const CB_HOME = 'home';
    public const CB_RESEND = 'resend';
    public const CB_NOTIFY_SLOTS = 'notify_slots';
    
    // Callback для админов
    public const CB_CHECK_APPROVE = 'check_approve';
    public const CB_CHECK_REJECT = 'check_reject';
    public const CB_CHECK_EDIT = 'check_edit';
    public const CB_EDIT_AMOUNT = 'edit_amount';
    public const CB_CONFIRM_APPROVE = 'confirm_approve';

    protected TelegramBot $bot;
    protected BotUser $user;
    protected BotSettings $settings;

    public function __construct(TelegramBot $bot, BotUser $user)
    {
        $this->bot = $bot;
        $this->user = $user;
        $this->settings = $bot->getOrCreateSettings();
    }

    // ==========================================
    // Основные методы
    // ==========================================

    /**
     * Получить текущее состояние
     */
    public function getState(): string
    {
        return $this->user->fsm_state ?? self::STATE_IDLE;
    }

    /**
     * Установить состояние
     */
    public function setState(string $state, array $data = []): self
    {
        $this->user->setState($state, $data);
        Log::info("FSM: State changed", [
            'user_id' => $this->user->telegram_user_id,
            'new_state' => $state,
            'data' => $data,
        ]);
        return $this;
    }

    /**
     * Сохранить ID последнего сообщения бота
     */
    public function setLastMessageId(int $messageId): self
    {
        $this->user->last_bot_message_id = $messageId;
        $this->user->save();
        return $this;
    }

    /**
     * Получить ID последнего сообщения бота
     */
    public function getLastMessageId(): ?int
    {
        return $this->user->last_bot_message_id;
    }

    /**
     * Сбросить состояние
     */
    public function reset(): self
    {
        $this->user->resetState();
        return $this;
    }

    /**
     * Сохранить данные FSM
     */
    public function setData(array $data): self
    {
        $currentData = $this->user->fsm_data ?? [];
        $this->user->fsm_data = array_merge($currentData, $data);
        $this->user->save();
        return $this;
    }

    /**
     * Получить данные FSM
     */
    public function getData(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->user->fsm_data ?? [];
        }
        return $this->user->fsm_data[$key] ?? $default;
    }

    // ==========================================
    // Проверки
    // ==========================================

    /**
     * Проверить, есть ли свободные места
     */
    public function hasAvailableSlots(): bool
    {
        return $this->settings->hasAvailableSlots();
    }

    /**
     * Проверить, заполнены ли все данные пользователя
     */
    public function hasAllUserData(): bool
    {
        return $this->user->hasAllPersonalData();
    }

    /**
     * Проверить, является ли пользователь админом
     */
    public function isAdmin(): bool
    {
        return $this->user->isAdmin();
    }

    // ==========================================
    // Генерация клавиатур
    // ==========================================

    /**
     * Базовые навигационные кнопки
     */
    public static function getNavButtons(bool $showBack = true, bool $showCancel = true): array
    {
        $buttons = [];
        
        if ($showBack) {
            $buttons[] = ['text' => '◀️ Назад', 'callback_data' => self::CB_BACK];
        }
        if ($showCancel) {
            $buttons[] = ['text' => '❌ Отмена', 'callback_data' => self::CB_CANCEL];
        }
        
        return $buttons;
    }

    /**
     * Кнопка "В начало"
     */
    public static function getHomeButton(): array
    {
        return ['text' => '🏠 В начало', 'callback_data' => self::CB_HOME];
    }

    /**
     * Кнопка "Отправить заново"
     */
    public static function getResendButton(): array
    {
        return ['text' => '🔄 Отправить заново', 'callback_data' => self::CB_RESEND];
    }

    /**
     * Клавиатура для экрана приветствия (места есть)
     */
    public function getWelcomeKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '🎯 Участвовать', 'callback_data' => self::CB_PARTICIPATE]],
            ]
        ];
    }

    /**
     * Клавиатура для экрана "нет мест"
     */
    public function getNoSlotsKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '🔔 Уведомить о появлении мест', 'callback_data' => self::CB_NOTIFY_SLOTS]],
                [['text' => '📢 Перейти в канал', 'url' => 'https://t.me/your_channel']], // TODO: сделать настраиваемым
            ]
        ];
    }

    /**
     * Клавиатура для ввода данных (ФИО/телефон/ИНН)
     */
    public function getInputKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                self::getNavButtons(true, true),
            ]
        ];
    }

    /**
     * Клавиатура для подтверждения данных
     */
    public function getConfirmDataKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '✅ Всё верно', 'callback_data' => self::CB_CONFIRM_DATA]],
                [['text' => '✏️ Изменить данные', 'callback_data' => self::CB_EDIT_DATA]],
                self::getNavButtons(false, true),
            ]
        ];
    }

    /**
     * Клавиатура для экрана с QR-кодом
     */
    public function getShowQrKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [self::getHomeButton()],
            ]
        ];
    }

    /**
     * Клавиатура для ожидания чека
     */
    public function getWaitCheckKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [self::getResendButton()],
                [self::getHomeButton()],
            ]
        ];
    }

    /**
     * Клавиатура для экрана "чек отклонён"
     */
    public function getRejectedKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [self::getResendButton()],
                [self::getHomeButton()],
            ]
        ];
    }

    /**
     * Клавиатура для администратора при проверке чека
     */
    public static function getAdminCheckKeyboard(int $checkId): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Одобрить', 'callback_data' => self::CB_CHECK_APPROVE . ':' . $checkId],
                    ['text' => '❌ Отклонить', 'callback_data' => self::CB_CHECK_REJECT . ':' . $checkId],
                ],
                [
                    ['text' => '✏️ Редактировать', 'callback_data' => self::CB_CHECK_EDIT . ':' . $checkId],
                ],
            ]
        ];
    }

    /**
     * Клавиатура для редактирования суммы админом
     */
    public static function getAdminEditAmountKeyboard(int $checkId): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Подтвердить изменения', 'callback_data' => self::CB_CONFIRM_APPROVE . ':' . $checkId],
                ],
                [
                    ['text' => '❌ Отмена', 'callback_data' => self::CB_CANCEL],
                ],
            ]
        ];
    }

    // ==========================================
    // Методы для работы с сообщениями
    // ==========================================

    /**
     * Получить текст для текущего состояния
     */
    public function getStateMessage(): string
    {
        $state = $this->getState();
        
        switch ($state) {
            case self::STATE_WELCOME:
                return $this->hasAvailableSlots() 
                    ? $this->settings->getWelcomeMessage()
                    : $this->settings->getNoSlotsMessage();
                    
            case self::STATE_WAIT_FIO:
                return $this->settings->getMessage('ask_fio');
                
            case self::STATE_WAIT_PHONE:
                return $this->settings->getMessage('ask_phone');
                
            case self::STATE_WAIT_INN:
                return $this->settings->getMessage('ask_inn');
                
            case self::STATE_CONFIRM_DATA:
                return $this->settings->getMessage('confirm_data', [
                    'fio' => $this->getData('fio', '—'),
                    'phone' => $this->getData('phone', '—'),
                    'inn' => $this->getData('inn', '—'),
                ]);
                
            case self::STATE_SHOW_QR:
                return $this->settings->getShowQrMessage();
                
            case self::STATE_WAIT_CHECK:
                return $this->settings->getMessage('wait_check');
                
            case self::STATE_PENDING_REVIEW:
                return $this->settings->getMessage('check_received');
                
            case self::STATE_APPROVED:
                $tickets = $this->user->getTicketNumbers();
                return $this->settings->getCheckApprovedMessage($tickets);
                
            case self::STATE_REJECTED:
                return $this->settings->getMessage('check_rejected', [
                    'reason' => $this->getData('reject_reason', ''),
                ]);
                
            default:
                return 'Неизвестное состояние. Нажмите /start для начала.';
        }
    }

    /**
     * Получить клавиатуру для текущего состояния
     */
    public function getStateKeyboard(): ?array
    {
        $state = $this->getState();
        
        switch ($state) {
            case self::STATE_WELCOME:
                return $this->hasAvailableSlots() 
                    ? $this->getWelcomeKeyboard()
                    : $this->getNoSlotsKeyboard();
                    
            case self::STATE_WAIT_FIO:
            case self::STATE_WAIT_PHONE:
            case self::STATE_WAIT_INN:
                return $this->getInputKeyboard();
                
            case self::STATE_CONFIRM_DATA:
                return $this->getConfirmDataKeyboard();
                
            case self::STATE_SHOW_QR:
                return $this->getShowQrKeyboard();
                
            case self::STATE_WAIT_CHECK:
                return $this->getWaitCheckKeyboard();
                
            case self::STATE_REJECTED:
                return $this->getRejectedKeyboard();
                
            default:
                return null;
        }
    }
}
