<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotUser;
use App\Models\BotSettings;
use App\Models\Check;
use App\Models\TelegramBot;
use App\Models\AdminRequest;
use App\Models\Ticket;
use App\Models\AdminActionLog;
use App\Services\Telegram\FSM\BotFSM;
use App\Services\Telegram\TelegramMenuService;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Контроллер webhook для розыгрыша номерков
 * Использует FSM для управления состояниями пользователя
 */
class RaffleWebhookController extends Controller
{
    protected ?TelegramBot $bot = null;
    protected ?BotUser $botUser = null;
    protected ?BotSettings $settings = null;
    protected ?TelegramService $telegram = null;
    protected ?BotFSM $fsm = null;

    /**
     * Основной обработчик webhook
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $update = $request->all();
            Log::info('Raffle webhook received', ['update_id' => $update['update_id'] ?? null]);

            // Находим бота
            $this->bot = $this->findBot($update);
            if (!$this->bot) {
                Log::warning('Bot not found for update');
                return response()->json(['ok' => true]);
            }

            // Инициализируем сервисы
            $this->telegram = new TelegramService($this->bot);
            $this->settings = $this->bot->getOrCreateSettings();

            // Обрабатываем сообщение
            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            }

            // Обрабатываем callback query (нажатия на кнопки)
            if (isset($update['callback_query'])) {
                $this->handleCallbackQuery($update['callback_query']);
            }

            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('Raffle webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['ok' => true]);
        }
    }

    /**
     * Найти бота по update
     */
    private function findBot(array $update): ?TelegramBot
    {
        $bots = TelegramBot::where('is_active', true)->get();
        
        if ($bots->count() === 1) {
            return $bots->first();
        }
        
        return $bots->first();
    }

    /**
     * Инициализировать пользователя и FSM
     */
    private function initUser(array $from, int $chatId): void
    {
        $this->botUser = $this->bot->findOrCreateBotUser([
            'id' => $chatId,
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'last_name' => $from['last_name'] ?? null,
        ]);

        $this->fsm = new BotFSM($this->bot, $this->botUser);
    }

    // ==========================================
    // ОБРАБОТКА СООБЩЕНИЙ
    // ==========================================

    /**
     * Обработка входящего сообщения
     */
    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $from = $message['from'] ?? [];
        $text = $message['text'] ?? null;
        $document = $message['document'] ?? null;
        $photo = $message['photo'] ?? null;

        // Инициализируем пользователя
        $this->initUser($from, $chatId);

        // Команды
        if ($text) {
            if (str_starts_with($text, '/start')) {
                $this->handleStartCommand();
                return;
            }
            if (str_starts_with($text, '/admin')) {
                $this->handleAdminCommand();
                return;
            }
            if (str_starts_with($text, '/status')) {
                $this->handleStatusCommand();
                return;
            }
            if (str_starts_with($text, '/help')) {
                $this->handleHelpCommand();
                return;
            }
        }

        // Обработка нажатий кнопок постоянного меню (Reply Keyboard)
        if ($text) {
            if ($text === TelegramMenuService::BTN_HOME) {
                $this->handleStartCommand();
                return;
            }
            if ($text === TelegramMenuService::BTN_ABOUT) {
                $this->handleAboutRaffle();
                return;
            }
            if ($text === TelegramMenuService::BTN_MY_TICKETS) {
                $this->handleMyTickets();
                return;
            }
            if ($text === TelegramMenuService::BTN_SUPPORT) {
                $this->handleSupport();
                return;
            }
            if ($text === '🎯 Участвовать' && $this->fsm->getState() === BotFSM::STATE_WELCOME) {
                $this->onParticipate();
                return;
            }
        }

        // Обработка по текущему состоянию FSM
        $state = $this->fsm->getState();

        switch ($state) {
            case BotFSM::STATE_WAIT_FIO:
                if ($text) {
                    $this->handleFioInput($text);
                }
                break;

            case BotFSM::STATE_WAIT_PHONE:
                if ($text) {
                    $this->handlePhoneInput($text);
                }
                break;

            case BotFSM::STATE_WAIT_INN:
                // ИНН убран из формы — переходим к подтверждению
                if ($text) {
                    $this->fsm->setState(BotFSM::STATE_CONFIRM_DATA);
                    $this->showConfirmDataScreen();
                }
                break;

            case BotFSM::STATE_WAIT_CHECK:
            case BotFSM::STATE_REJECTED:
                // Ожидаем чек (PDF или фото)
                if ($document && $this->isPdfDocument($document)) {
                    $this->handleCheckDocument($document);
                } elseif ($photo) {
                    $this->handleCheckPhoto($photo);
                } else {
                    $this->sendStateMessage();
                }
                break;

            case BotFSM::STATE_ADMIN_EDIT_AMOUNT:
                // Админ редактирует сумму
                if ($text && $this->botUser->isAdmin()) {
                    $this->handleAdminAmountInput($text);
                }
                break;

            default:
                // Для неизвестных состояний отправляем в начало (с меню)
                if ($text && !str_starts_with($text, '/')) {
                    $this->telegram->sendMessageWithReplyKeyboard(
                        $chatId,
                        "Используйте /start для начала работы с ботом."
                    );
                }
                break;
        }
    }

    // ==========================================
    // КОМАНДЫ
    // ==========================================

    /**
     * /start - начало работы. Отправляем приветствие с постоянной Reply Keyboard (меню всегда видно).
     */
    private function handleStartCommand(): void
    {
        $chatId = $this->botUser->telegram_user_id;

        // Сбрасываем состояние
        $this->fsm->reset();

        // Постоянная Reply Keyboard — отправляем первым сообщением, чтобы она отображалась ВСЕГДА
        $replyKeyboard = TelegramMenuService::getReplyKeyboardArray();

        // Проверяем наличие мест
        if (!$this->settings->hasAvailableSlots()) {
            $this->fsm->setState(BotFSM::STATE_WELCOME);
            $message = $this->settings->getNoSlotsMessage();
            $result = $this->telegram->sendMessage($chatId, $message, $replyKeyboard);
            if ($result && isset($result['result']['message_id'])) {
                $this->fsm->setLastMessageId($result['result']['message_id']);
            }
            // Второе сообщение с inline-кнопками (уведомить о местах и т.д.)
            $this->telegram->sendMessage($chatId, '👇', $this->fsm->getNoSlotsKeyboard());
        } else {
            $this->fsm->setState(BotFSM::STATE_WELCOME);
            $message = $this->settings->getWelcomeMessage();
            // Первое сообщение: текст приветствия + постоянная клавиатура (Главная, О розыгрыше, Мои номерки, Поддержка)
            $result = $this->telegram->sendMessage($chatId, $message, $replyKeyboard);
            if ($result && isset($result['result']['message_id'])) {
                $this->fsm->setLastMessageId($result['result']['message_id']);
            }
            // Второе сообщение: inline-кнопка "Участвовать"
            $this->telegram->sendMessage($chatId, '👇 Нажмите кнопку ниже, чтобы участвовать', $this->fsm->getWelcomeKeyboard());
        }
    }

    /**
     * /admin - запрос на роль администратора
     */
    private function handleAdminCommand(): void
    {
        $chatId = $this->botUser->telegram_user_id;

        // Если уже админ
        if ($this->botUser->isAdmin()) {
            $this->telegram->sendMessageWithReplyKeyboard($chatId, "✅ Вы уже являетесь администратором.");
            return;
        }

        // Проверяем, есть ли уже активный запрос
        if (AdminRequest::hasPendingRequest($this->botUser->id)) {
            $this->telegram->sendMessageWithReplyKeyboard(
                $chatId,
                "⏳ Ваш запрос на роль администратора уже на рассмотрении."
            );
            return;
        }

        // Создаём запрос
        AdminRequest::createRequest($this->botUser);

        $message = $this->settings->getMessage('admin_request_sent');
        $this->telegram->sendMessageWithReplyKeyboard($chatId, $message);

        // Уведомляем существующих админов
        $this->notifyAdminsAboutRequest();
    }

    /**
     * Обработка кнопки "О розыгрыше" (постоянное меню)
     */
    private function handleAboutRaffle(): void
    {
        $menu = new TelegramMenuService($this->bot);
        $menu->handleAboutRaffle($this->botUser->telegram_user_id, $this->botUser);
    }

    /**
     * Обработка кнопки "Мои номерки" (постоянное меню)
     */
    private function handleMyTickets(): void
    {
        $menu = new TelegramMenuService($this->bot);
        $menu->handleMyTickets($this->botUser->telegram_user_id, $this->botUser);
    }

    /**
     * Обработка кнопки "Поддержка" (постоянное меню)
     */
    private function handleSupport(): void
    {
        $menu = new TelegramMenuService($this->bot);
        $menu->handleSupport($this->botUser->telegram_user_id);
    }

    /**
     * /status - статус пользователя
     */
    private function handleStatusCommand(): void
    {
        $chatId = $this->botUser->telegram_user_id;
        $tickets = $this->botUser->getTicketNumbers();

        if (empty($tickets)) {
            $message = "📊 Ваш статус:\n\n🎫 Номерков: 0\n\nПройдите регистрацию командой /start";
        } else {
            $message = "📊 Ваш статус:\n\n"
                . "🎫 Ваши номерки: " . implode(', ', $tickets) . "\n"
                . "📝 Всего номерков: " . count($tickets);
        }

        $this->telegram->sendMessageWithReplyKeyboard($chatId, $message);
    }

    /**
     * /help - справка
     */
    private function handleHelpCommand(): void
    {
        $chatId = $this->botUser->telegram_user_id;

        $message = "📖 Справка по боту\n\n"
            . "🎯 /start - Начать участие в розыгрыше\n"
            . "📊 /status - Проверить свои номерки\n"
            . "❓ /help - Эта справка\n\n"
            . "💰 Стоимость участия: " . number_format($this->settings->slot_price, 0, '', ' ') . " ₽ = 1 номерок\n"
            . "📊 Свободных мест: " . $this->settings->getAvailableSlotsCount() . " из " . $this->settings->total_slots;

        $this->telegram->sendMessageWithReplyKeyboard($chatId, $message);
    }

    // ==========================================
    // ВВОД ДАННЫХ
    // ==========================================

    /**
     * Обработка ввода ФИО
     */
    private function handleFioInput(string $text): void
    {
        $text = trim($text);

        // Валидация ФИО (минимум 2 слова)
        $words = preg_split('/\s+/', $text);
        if (count($words) < 2) {
            $this->telegram->sendOrEditMessage(
                $this->botUser,
                "❌ Пожалуйста, введите полное ФИО (минимум Фамилия и Имя).\n\n" 
                . $this->settings->getMessage('ask_fio'),
                $this->fsm->getInputKeyboard()
            );
            return;
        }

        // Сохраняем ФИО
        $this->fsm->setData(['fio' => $text]);
        
        // Переходим к вводу телефона
        $this->fsm->setState(BotFSM::STATE_WAIT_PHONE);
        
        $this->telegram->sendOrEditMessage(
            $this->botUser,
            $this->settings->getMessage('ask_phone'),
            $this->fsm->getInputKeyboard()
        );
    }

    /**
     * Обработка ввода телефона
     */
    private function handlePhoneInput(string $text): void
    {
        $text = trim($text);

        // Нормализуем номер телефона
        $phone = preg_replace('/[^\d+]/', '', $text);

        // Валидация (минимум 10 цифр)
        if (strlen(preg_replace('/\D/', '', $phone)) < 10) {
            $this->telegram->sendOrEditMessage(
                $this->botUser,
                "❌ Неверный формат номера телефона.\n\n" 
                . $this->settings->getMessage('ask_phone'),
                $this->fsm->getInputKeyboard()
            );
            return;
        }

        $this->fsm->setData(['phone' => $phone]);
        $this->fsm->setState(BotFSM::STATE_CONFIRM_DATA);
        $this->showConfirmDataScreen();
    }

    /**
     * Показать экран подтверждения данных
     */
    private function showConfirmDataScreen(): void
    {
        $fio = $this->fsm->getData('fio');
        $phone = $this->fsm->getData('phone');

        $message = $this->settings->getMessage('confirm_data', [
            'fio' => $fio,
            'phone' => $phone,
            'inn' => '',
        ]);

        $this->telegram->sendOrEditMessage(
            $this->botUser,
            $message,
            $this->fsm->getConfirmDataKeyboard()
        );
    }

    // ==========================================
    // ОБРАБОТКА ЧЕКОВ
    // ==========================================

    /**
     * Обработка документа (PDF чека)
     */
    private function handleCheckDocument(array $document): void
    {
        $chatId = $this->botUser->telegram_user_id;
        $fileId = $document['file_id'];
        $fileName = $document['file_name'] ?? 'check.pdf';
        $fileSize = $document['file_size'] ?? 0;

        // Скачиваем файл
        $fileInfo = $this->telegram->getFile($fileId);
        if (!$fileInfo || !isset($fileInfo['result']['file_path'])) {
            $this->telegram->sendMessageWithReplyKeyboard($chatId, "❌ Не удалось загрузить файл. Попробуйте снова.");
            return;
        }

        $filePath = $fileInfo['result']['file_path'];
        $localPath = 'checks/' . $this->botUser->id . '_' . time() . '_' . $fileName;

        if (!$this->telegram->downloadFile($filePath, $localPath)) {
            $this->telegram->sendMessageWithReplyKeyboard($chatId, "❌ Ошибка загрузки файла. Попробуйте снова.");
            return;
        }

        // Создаём запись чека
        $this->createCheckRecord($localPath, 'pdf', $fileSize);
    }

    /**
     * Обработка фото чека
     */
    private function handleCheckPhoto(array $photo): void
    {
        $chatId = $this->botUser->telegram_user_id;

        // Берём самое большое фото
        $photoSizes = array_reverse($photo);
        $largestPhoto = $photoSizes[0];
        $fileId = $largestPhoto['file_id'];
        $fileSize = $largestPhoto['file_size'] ?? 0;

        // Скачиваем файл
        $fileInfo = $this->telegram->getFile($fileId);
        if (!$fileInfo || !isset($fileInfo['result']['file_path'])) {
            $this->telegram->sendMessageWithReplyKeyboard($chatId, "❌ Не удалось загрузить фото. Попробуйте снова.");
            return;
        }

        $filePath = $fileInfo['result']['file_path'];
        $localPath = 'checks/' . $this->botUser->id . '_' . time() . '.jpg';

        if (!$this->telegram->downloadFile($filePath, $localPath)) {
            $this->telegram->sendMessageWithReplyKeyboard($chatId, "❌ Ошибка загрузки фото. Попробуйте снова.");
            return;
        }

        // Создаём запись чека
        $this->createCheckRecord($localPath, 'image', $fileSize);
    }

    /**
     * Создать запись чека и уведомить админов
     */
    private function createCheckRecord(string $filePath, string $fileType, int $fileSize): void
    {
        $chatId = $this->botUser->telegram_user_id;

        $this->botUser->fio = $this->fsm->getData('fio');
        $this->botUser->phone = $this->fsm->getData('phone');
        $this->botUser->save();

        // TODO: Здесь можно добавить OCR для распознавания суммы
        // Пока создаём запись с пустой суммой - админ введёт вручную

        // Создаём чек
        $check = Check::create([
            'telegram_bot_id' => $this->bot->id,
            'bot_user_id' => $this->botUser->id,
            'chat_id' => $chatId,
            'username' => $this->botUser->username,
            'first_name' => $this->botUser->first_name,
            'file_path' => $filePath,
            'file_type' => $fileType,
            'file_size' => $fileSize,
            'status' => 'pending',
            'review_status' => 'pending',
        ]);

        // Переходим в состояние ожидания проверки
        $this->fsm->setState(BotFSM::STATE_PENDING_REVIEW, ['check_id' => $check->id]);

        // Отправляем подтверждение пользователю
        $message = $this->settings->getMessage('check_received');
        $this->telegram->sendMessageWithReplyKeyboard($chatId, $message);

        // Уведомляем администраторов
        $this->notifyAdminsAboutCheck($check);
    }

    // ==========================================
    // CALLBACK QUERY (КНОПКИ)
    // ==========================================

    /**
     * Обработка нажатия на кнопку
     */
    private function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackId = $callbackQuery['id'];
        $data = $callbackQuery['data'] ?? '';
        $from = $callbackQuery['from'] ?? [];
        $chatId = $from['id'] ?? 0;
        $messageId = $callbackQuery['message']['message_id'] ?? null;

        // Инициализируем пользователя
        $this->initUser($from, $chatId);

        // Отвечаем на callback чтобы убрать "часики"
        $this->telegram->answerCallbackQuery($callbackId);

        // Парсим callback data
        $parts = explode(':', $data);
        $action = $parts[0];
        $param = $parts[1] ?? null;

        switch ($action) {
            // Навигация
            case BotFSM::CB_PARTICIPATE:
                $this->onParticipate();
                break;

            case BotFSM::CB_BACK:
                $this->onBack();
                break;

            case BotFSM::CB_CANCEL:
            case BotFSM::CB_HOME:
                $this->handleStartCommand();
                break;

            case BotFSM::CB_RESEND:
                $this->onResend();
                break;

            case BotFSM::CB_NOTIFY_SLOTS:
                $this->onNotifySlots();
                break;

            // Подтверждение данных
            case BotFSM::CB_CONFIRM_DATA:
                $this->onConfirmData();
                break;

            case BotFSM::CB_EDIT_DATA:
                $this->onEditData();
                break;

            // Действия администратора
            case BotFSM::CB_CHECK_APPROVE:
                if ($param && $this->botUser->isAdmin()) {
                    $this->onAdminApproveCheck((int)$param);
                }
                break;

            case BotFSM::CB_CHECK_REJECT:
                if ($param && $this->botUser->isAdmin()) {
                    $this->onAdminRejectCheck((int)$param);
                }
                break;

            case BotFSM::CB_CHECK_EDIT:
                if ($param && $this->botUser->isAdmin()) {
                    $this->onAdminEditCheck((int)$param);
                }
                break;

            case BotFSM::CB_CONFIRM_APPROVE:
                if ($param && $this->botUser->isAdmin()) {
                    $this->onAdminConfirmApprove((int)$param);
                }
                break;
        }
    }

    /**
     * Нажатие "Участвовать"
     */
    private function onParticipate(): void
    {
        // Проверяем наличие мест
        if (!$this->settings->hasAvailableSlots()) {
            $this->telegram->sendOrEditMessage(
                $this->botUser,
                $this->settings->getNoSlotsMessage(),
                $this->fsm->getNoSlotsKeyboard()
            );
            return;
        }

        // Переходим к вводу ФИО
        $this->fsm->setState(BotFSM::STATE_WAIT_FIO);

        $this->telegram->sendOrEditMessage(
            $this->botUser,
            $this->settings->getMessage('ask_fio'),
            $this->fsm->getInputKeyboard()
        );
    }

    /**
     * Нажатие "Назад"
     */
    private function onBack(): void
    {
        $state = $this->fsm->getState();

        switch ($state) {
            case BotFSM::STATE_WAIT_FIO:
                // Возврат к приветствию
                $this->handleStartCommand();
                break;

            case BotFSM::STATE_WAIT_PHONE:
                // Возврат к вводу ФИО
                $this->fsm->setState(BotFSM::STATE_WAIT_FIO);
                $this->telegram->sendOrEditMessage(
                    $this->botUser,
                    $this->settings->getMessage('ask_fio'),
                    $this->fsm->getInputKeyboard()
                );
                break;

            case BotFSM::STATE_CONFIRM_DATA:
                $this->fsm->setState(BotFSM::STATE_WAIT_PHONE);
                $this->telegram->sendOrEditMessage(
                    $this->botUser,
                    $this->settings->getMessage('ask_phone'),
                    $this->fsm->getInputKeyboard()
                );
                break;

            default:
                $this->handleStartCommand();
                break;
        }
    }

    /**
     * Нажатие "Отправить заново"
     */
    private function onResend(): void
    {
        $this->fsm->setState(BotFSM::STATE_WAIT_CHECK);
        $this->telegram->sendOrEditMessage(
            $this->botUser,
            $this->settings->getMessage('wait_check'),
            $this->fsm->getWaitCheckKeyboard()
        );
    }

    /**
     * Нажатие "Уведомить о появлении мест"
     */
    private function onNotifySlots(): void
    {
        $this->botUser->notify_on_slots_available = true;
        $this->botUser->save();

        $this->telegram->sendMessageWithReplyKeyboard(
            $this->botUser->telegram_user_id,
            "🔔 Вы будете уведомлены, когда появятся свободные места!"
        );
    }

    /**
     * Подтверждение данных
     */
    private function onConfirmData(): void
    {
        $this->botUser->fio = $this->fsm->getData('fio');
        $this->botUser->phone = $this->fsm->getData('phone');
        $this->botUser->save();

        // Переходим к показу QR-кода
        $this->fsm->setState(BotFSM::STATE_SHOW_QR);

        // Отправляем QR-код
        $qrPath = $this->settings->getQrImageFullPath();
        $caption = $this->settings->getShowQrMessage();

        if ($qrPath && file_exists($qrPath)) {
            $this->telegram->sendPhoto(
                $this->botUser->telegram_user_id,
                $qrPath,
                $caption,
                $this->fsm->getShowQrKeyboard()
            );
        } else {
            // Если QR нет, просто отправляем сообщение
            $this->telegram->sendMessage(
                $this->botUser->telegram_user_id,
                $caption,
                $this->fsm->getShowQrKeyboard()
            );
        }

        // После показа QR переходим к ожиданию чека
        $this->fsm->setState(BotFSM::STATE_WAIT_CHECK);

        // Отправляем инструкцию
        $result = $this->telegram->sendMessage(
            $this->botUser->telegram_user_id,
            $this->settings->getMessage('wait_check'),
            $this->fsm->getWaitCheckKeyboard()
        );

        if ($result && isset($result['result']['message_id'])) {
            $this->fsm->setLastMessageId($result['result']['message_id']);
        }
    }

    /**
     * Редактирование данных (вернуться к вводу ФИО)
     */
    private function onEditData(): void
    {
        $this->fsm->setState(BotFSM::STATE_WAIT_FIO);
        $this->telegram->sendOrEditMessage(
            $this->botUser,
            $this->settings->getMessage('ask_fio'),
            $this->fsm->getInputKeyboard()
        );
    }

    // ==========================================
    // ДЕЙСТВИЯ АДМИНИСТРАТОРА
    // ==========================================

    /**
     * Админ одобряет чек
     */
    private function onAdminApproveCheck(int $checkId): void
    {
        $check = Check::find($checkId);
        if (!$check || $check->review_status !== 'pending') {
            $this->telegram->sendMessage(
                $this->botUser->telegram_user_id,
                "❌ Чек не найден или уже обработан."
            );
            return;
        }

        // Рассчитываем количество номерков
        $amount = $check->final_amount ?? 0;
        $ticketsCount = $this->settings->calculateTicketsCount($amount);

        if ($ticketsCount < 1) {
            $this->telegram->sendMessage(
                $this->botUser->telegram_user_id,
                "❌ Сумма {$amount} ₽ недостаточна для выдачи номерков.\n"
                . "Минимальная сумма: {$this->settings->slot_price} ₽\n\n"
                . "Отредактируйте сумму или отклоните чек."
            );
            return;
        }

        // Проверяем наличие мест
        if (!$this->settings->hasEnoughSlots($ticketsCount)) {
            $available = $this->settings->getAvailableSlotsCount();
            $this->telegram->sendMessage(
                $this->botUser->telegram_user_id,
                "❌ Недостаточно свободных мест!\n"
                . "Требуется: {$ticketsCount}, доступно: {$available}\n\n"
                . "Отклоните чек или измените сумму."
            );
            return;
        }

        // Выдаём номерки
        $checkUser = $check->botUser;
        if (!$checkUser) {
            $this->telegram->sendMessage(
                $this->botUser->telegram_user_id,
                "❌ Пользователь чека не найден."
            );
            return;
        }

        $tickets = Ticket::issueTickets(
            $this->bot->id,
            $checkUser,
            $ticketsCount,
            $check,
            $this->settings->slots_mode
        );

        // Обновляем чек
        $check->approve($ticketsCount, null, null);

        // Логируем действие
        AdminActionLog::logCheckApproved($check, null, $this->botUser->telegram_user_id);

        // Уведомляем пользователя
        $ticketNumbers = $tickets->pluck('number')->sort()->values()->toArray();
        $userMessage = $this->settings->getCheckApprovedMessage($ticketNumbers);
        
        $this->telegram->sendMessageWithReplyKeyboard($checkUser->telegram_user_id, $userMessage);

        // Подтверждаем админу
        $this->telegram->sendMessage(
            $this->botUser->telegram_user_id,
            "✅ Чек #{$checkId} одобрен!\n"
            . "Выдано номерков: {$ticketsCount}\n"
            . "Номера: " . implode(', ', $ticketNumbers)
        );
    }

    /**
     * Админ отклоняет чек
     */
    private function onAdminRejectCheck(int $checkId): void
    {
        $check = Check::find($checkId);
        if (!$check || $check->review_status !== 'pending') {
            $this->telegram->sendMessage(
                $this->botUser->telegram_user_id,
                "❌ Чек не найден или уже обработан."
            );
            return;
        }

        // Отклоняем чек
        $check->reject(null, 'Отклонено администратором');

        // Логируем действие
        AdminActionLog::logCheckRejected($check, null, $this->botUser->telegram_user_id);

        // Уведомляем пользователя
        $checkUser = $check->botUser;
        if ($checkUser) {
            $checkUser->setState(BotFSM::STATE_REJECTED, ['reject_reason' => '']);
            
            $userMessage = $this->settings->getMessage('check_rejected', [
                'reason' => 'Проверьте правильность оплаты.',
            ]);
            $this->telegram->sendMessageWithReplyKeyboard($checkUser->telegram_user_id, $userMessage);
        }

        // Подтверждаем админу
        $this->telegram->sendMessage(
            $this->botUser->telegram_user_id,
            "❌ Чек #{$checkId} отклонён."
        );
    }

    /**
     * Админ редактирует чек
     */
    private function onAdminEditCheck(int $checkId): void
    {
        $check = Check::find($checkId);
        if (!$check) {
            $this->telegram->sendMessage(
                $this->botUser->telegram_user_id,
                "❌ Чек не найден."
            );
            return;
        }

        // Переводим админа в режим редактирования
        $this->fsm->setState(BotFSM::STATE_ADMIN_EDIT_AMOUNT, ['editing_check_id' => $checkId]);

        $currentAmount = $check->final_amount ?? 0;
        $this->telegram->sendMessage(
            $this->botUser->telegram_user_id,
            "✏️ Редактирование чека #{$checkId}\n\n"
            . "Текущая сумма: " . number_format($currentAmount, 2, '.', ' ') . " ₽\n\n"
            . "Введите новую сумму (только число):",
            BotFSM::getAdminEditAmountKeyboard($checkId)
        );
    }

    /**
     * Админ вводит новую сумму
     */
    private function handleAdminAmountInput(string $text): void
    {
        $checkId = $this->fsm->getData('editing_check_id');
        if (!$checkId) {
            return;
        }

        // Парсим сумму
        $amount = (float) preg_replace('/[^\d.]/', '', $text);
        
        if ($amount <= 0) {
            $this->telegram->sendMessage(
                $this->botUser->telegram_user_id,
                "❌ Введите корректную сумму (положительное число)."
            );
            return;
        }

        $check = Check::find($checkId);
        if (!$check) {
            $this->telegram->sendMessage(
                $this->botUser->telegram_user_id,
                "❌ Чек не найден."
            );
            return;
        }

        // Сохраняем старые данные для лога
        $oldAmount = $check->final_amount;

        // Обновляем сумму
        $check->editAmount($amount);

        // Логируем
        AdminActionLog::logCheckEdited(
            $check,
            ['amount' => $oldAmount],
            ['amount' => $amount],
            null,
            $this->botUser->telegram_user_id
        );

        // Рассчитываем номерки
        $ticketsCount = $this->settings->calculateTicketsCount($amount);

        // Сохраняем новую сумму в FSM
        $this->fsm->setData(['edited_amount' => $amount, 'tickets_count' => $ticketsCount]);

        $this->telegram->sendMessage(
            $this->botUser->telegram_user_id,
            "✏️ Сумма изменена!\n\n"
            . "Новая сумма: " . number_format($amount, 2, '.', ' ') . " ₽\n"
            . "Номерков к выдаче: {$ticketsCount}\n\n"
            . "Одобрить чек с этой суммой?",
            BotFSM::getAdminEditAmountKeyboard($checkId)
        );
    }

    /**
     * Подтверждение одобрения после редактирования
     */
    private function onAdminConfirmApprove(int $checkId): void
    {
        // Сбрасываем состояние редактирования
        $this->fsm->reset();

        // Одобряем чек
        $this->onAdminApproveCheck($checkId);
    }

    // ==========================================
    // УВЕДОМЛЕНИЯ
    // ==========================================

    /**
     * Уведомить админов о новом чеке
     */
    private function notifyAdminsAboutCheck(Check $check): void
    {
        $checkUser = $check->botUser;
        $amount = $check->final_amount ?? 'не определена';
        $ticketsCount = $check->calculateTicketsCount();

        $caption = "📄 <b>Новый чек на проверку!</b>\n\n"
            . "👤 Пользователь: " . ($checkUser ? $checkUser->getDisplayName() : 'Неизвестен') . "\n"
            . "📱 ID: " . ($checkUser ? $checkUser->telegram_user_id : $check->chat_id) . "\n"
            . "💰 Сумма: " . (is_numeric($amount) ? number_format($amount, 2, '.', ' ') . " ₽" : $amount) . "\n"
            . "🎫 Номерков: {$ticketsCount}\n"
            . "📅 Дата: " . now()->format('d.m.Y H:i');

        $keyboard = BotFSM::getAdminCheckKeyboard($check->id);
        $filePath = Storage::disk('local')->path($check->file_path);

        if (file_exists($filePath)) {
            $this->telegram->notifyAdminsWithDocument($filePath, $caption, $keyboard);
        } else {
            $this->telegram->notifyAdmins($caption, $keyboard);
        }
    }

    /**
     * Уведомить админов о запросе на роль
     */
    private function notifyAdminsAboutRequest(): void
    {
        $message = "👤 <b>Новый запрос на роль администратора</b>\n\n"
            . "Пользователь: " . $this->botUser->getDisplayName() . "\n"
            . "ID: " . $this->botUser->telegram_user_id . "\n"
            . "Username: @" . ($this->botUser->username ?? 'не указан');

        $this->telegram->notifyAdmins($message);
    }

    // ==========================================
    // ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // ==========================================

    /**
     * Отправить сообщение текущего состояния
     */
    private function sendStateMessage(): void
    {
        $message = $this->fsm->getStateMessage();
        $keyboard = $this->fsm->getStateKeyboard();

        $this->telegram->sendOrEditMessage($this->botUser, $message, $keyboard);
    }

    /**
     * Проверить, является ли документ PDF
     */
    private function isPdfDocument(array $document): bool
    {
        $mimeType = $document['mime_type'] ?? '';
        $fileName = $document['file_name'] ?? '';

        return $mimeType === 'application/pdf' 
            || str_ends_with(strtolower($fileName), '.pdf');
    }
}
