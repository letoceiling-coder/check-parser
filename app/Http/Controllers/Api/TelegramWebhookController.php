<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Check;
use App\Models\TelegramBot;
use App\Models\BotUser;
use App\Models\BotSettings;
use App\Models\Raffle;
use App\Services\Telegram\TelegramMenuService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramWebhookController extends Controller
{
    /**
     * Handle Telegram webhook
     */
    public function handle(Request $request): JsonResponse
    {
        $update = $request->all();
        Log::info('Telegram webhook received', [
            'update_id' => $update['update_id'] ?? null,
            'has_message' => isset($update['message']),
            'message_text' => $update['message']['text'] ?? null,
        ]);

        try {

            // Find bot by token (we need to identify which bot this update is for)
            // Telegram sends updates to webhook URL, we need to identify bot
            // For now, we'll get bot token from webhook URL or use first active bot
            // In production, you might want to use secret_token or bot_id in URL
            
            $bot = $this->findBotByUpdate($update);
            if (!$bot) {
                Log::warning('Bot not found for update', [
                    'update_id' => $update['update_id'] ?? null,
                    'has_message' => isset($update['message']),
                    'has_callback_query' => isset($update['callback_query'])
                ]);
                return response()->json(['ok' => true]); // Return ok to Telegram
            }

            Log::info('Bot found, processing update', [
                'bot_id' => $bot->id,
                'has_message' => isset($update['message']),
                'has_callback_query' => isset($update['callback_query'])
            ]);

            // Handle message
            if (isset($update['message'])) {
                $this->handleMessage($bot, $update['message']);
            }

            // Handle callback query (button clicks)
            if (isset($update['callback_query'])) {
                $this->handleCallbackQuery($bot, $update['callback_query']);
            }

            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('Telegram webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['ok' => true]); // Always return ok to Telegram
        }
    }

    /**
     * Find bot by update
     * Try to identify bot by checking all active bots and matching token
     */
    private function findBotByUpdate(array $update): ?TelegramBot
    {
        // Get all active bots
        $bots = TelegramBot::where('is_active', true)->get();
        
        Log::info('Finding bot by update', [
            'active_bots_count' => $bots->count(),
            'has_message' => isset($update['message']),
            'has_callback_query' => isset($update['callback_query'])
        ]);
        
        if ($bots->count() === 0) {
            Log::warning('No active bots found in database');
            return null;
        }
        
        // If only one bot, return it
        if ($bots->count() === 1) {
            Log::info('Using single active bot', ['bot_id' => $bots->first()->id]);
            return $bots->first();
        }
        
        // If multiple bots, we need to identify by bot_id or token
        // For now, try to get bot info from message and match
        // In production, you might want to use bot_id in webhook URL path
        
        // For now, return first active bot
        Log::info('Multiple bots found, using first active bot', ['bot_id' => $bots->first()->id]);
        return $bots->first();
    }

    /**
     * Handle incoming message
     */
    private function handleMessage(TelegramBot $bot, array $message): void
    {
        $chatId = $message['chat']['id'];
        $telegramUserId = $message['from']['id'] ?? $chatId;
        $text = $message['text'] ?? null;
        $photo = $message['photo'] ?? null;
        $document = $message['document'] ?? null;
        
        // Данные отправителя для статистики
        $from = $message['from'] ?? [];
        $userData = [
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'last_name' => $from['last_name'] ?? null,
        ];

        // Check if raffle mode is enabled for this bot
        $botSettings = BotSettings::where('telegram_bot_id', $bot->id)->first();
        $isRaffleMode = $botSettings && $botSettings->is_active;

        if ($isRaffleMode) {
            // Get or create BotUser for FSM
            $botUser = $this->getOrCreateBotUser($bot, $telegramUserId, $userData);
            $menuService = new TelegramMenuService($bot);
            
            // Handle /start command in raffle mode
            if ($text && str_starts_with($text, '/start')) {
                $this->handleRaffleStart($bot, $botUser, $chatId, $botSettings);
                return;
            }
            
            // Handle /admin command
            if ($text && str_starts_with($text, '/admin')) {
                $this->handleAdminRequest($bot, $botUser, $chatId);
                return;
            }
            
            // Handle /status command
            if ($text && str_starts_with($text, '/status')) {
                $this->handleStatusCommand($bot, $botUser, $chatId);
                return;
            }
            
            // === Обработка кнопок постоянного меню ===
            if ($text === TelegramMenuService::BTN_HOME) {
                // Полный сброс FSM и возврат на стартовый экран
                $botUser->update(['fsm_state' => BotUser::STATE_IDLE]);
                $this->handleRaffleStart($bot, $botUser, $chatId, $botSettings);
                return;
            }
            
            if ($text === TelegramMenuService::BTN_ABOUT) {
                // Информация о розыгрыше (FSM не меняем)
                $menuService->handleAboutRaffle($chatId, $botUser);
                return;
            }
            
            if ($text === TelegramMenuService::BTN_MY_TICKETS) {
                // Мои номерки (FSM не меняем)
                $menuService->handleMyTickets($chatId, $botUser);
                return;
            }
            
            if ($text === TelegramMenuService::BTN_SUPPORT) {
                // Поддержка (FSM не меняем)
                $menuService->handleSupport($chatId);
                return;
            }
            // === Конец обработки кнопок меню ===
            
            // Handle FSM states
            $this->handleRaffleFSM($bot, $botUser, $chatId, $message, $botSettings);
            return;
        }

        // Original check processing mode
        // Handle /start command
        if ($text && str_starts_with($text, '/start')) {
            $this->handleStartCommand($bot, $chatId);
            return;
        }

        // Handle photo (check image)
        if ($photo) {
            $this->handlePhoto($bot, $chatId, $photo, $userData);
            return;
        }

        // Handle document (check image file or PDF)
        if ($document && ($this->isImageDocument($document) || $this->isPdfDocument($document))) {
            $this->handleDocument($bot, $chatId, $document, $userData);
            return;
        }

        // Handle other messages
        if ($text) {
            $this->sendMessage($bot, $chatId, 'Пожалуйста, отправьте фото чека для обработки.');
        }
    }
    
    /**
     * Get or create BotUser
     */
    private function getOrCreateBotUser(TelegramBot $bot, int $telegramUserId, array $userData): BotUser
    {
        return BotUser::firstOrCreate(
            ['telegram_bot_id' => $bot->id, 'telegram_user_id' => $telegramUserId],
            [
                'username' => $userData['username'] ?? null,
                'first_name' => $userData['first_name'] ?? null,
                'last_name' => $userData['last_name'] ?? null,
                'role' => BotUser::ROLE_USER,
                'fsm_state' => BotUser::STATE_IDLE,
            ]
        );
    }
    
    /**
     * Handle raffle /start command
     */
    private function handleRaffleStart(TelegramBot $bot, BotUser $botUser, int $chatId, BotSettings $settings): void
    {
        Log::info('Handling raffle /start', ['bot_id' => $bot->id, 'user_id' => $botUser->id]);
        
        // Удаляем предыдущее inline сообщение если есть
        if ($botUser->last_bot_message_id) {
            $this->deleteMessage($bot, $chatId, $botUser->last_bot_message_id);
        }
        
        // Check available slots
        $availableSlots = $settings->getAvailableSlotsCount();
        
        if ($availableSlots <= 0 || !$settings->is_active) {
            // No slots available
            $message = $settings->msg_no_slots ?? "К сожалению, все места уже заняты.\n\nСледите за новостями!";
            $message = str_replace('{total_slots}', $settings->total_slots, $message);
            
            // Отправляем с постоянной клавиатурой (без inline кнопок)
            $this->sendMessage($bot, $chatId, $message, true);
            $botUser->update(['fsm_state' => BotUser::STATE_IDLE, 'last_bot_message_id' => null]);
            return;
        }
        
        // Отправляем сначала сообщение с постоянной клавиатурой
        $this->sendMessage($bot, $chatId, "⌨️ Меню активировано", true);
        
        // Show welcome with price
        $message = $settings->msg_welcome ?? "Добро пожаловать в розыгрыш! 🎉\n\nСтоимость участия: {price} ₽ = 1 номерок\nДоступно мест: {available_slots} из {total_slots}\n\nНажмите \"Участвовать\" чтобы начать!";
        $message = str_replace('{price}', number_format($settings->slot_price, 0, ',', ' '), $message);
        $message = str_replace('{available_slots}', $availableSlots, $message);
        $message = str_replace('{total_slots}', $settings->total_slots, $message);
        
        // Inline кнопки для участия
        $inlineKeyboard = [
            'inline_keyboard' => [
                [['text' => '🎉 Участвовать', 'callback_data' => 'participate']],
            ]
        ];
        
        $result = $this->sendMessageWithKeyboard($bot, $chatId, $message, $inlineKeyboard);
        
        // Save message ID for editing
        if ($result && isset($result['message_id'])) {
            $botUser->update([
                'fsm_state' => BotUser::STATE_WELCOME,
                'last_bot_message_id' => $result['message_id']
            ]);
        }
    }
    
    /**
     * Handle admin request command
     */
    private function handleAdminRequest(TelegramBot $bot, BotUser $botUser, int $chatId): void
    {
        if ($botUser->isAdmin()) {
            $this->sendMessage($bot, $chatId, "Вы уже являетесь администратором.");
            return;
        }
        
        // Check existing pending request
        $existingRequest = \App\Models\AdminRequest::where('bot_user_id', $botUser->id)
            ->where('status', \App\Models\AdminRequest::STATUS_PENDING)
            ->first();
            
        if ($existingRequest) {
            $this->sendMessage($bot, $chatId, "Ваш запрос на роль администратора уже находится на рассмотрении.");
            return;
        }
        
        // Create request
        \App\Models\AdminRequest::create([
            'telegram_bot_id' => $bot->id,
            'bot_user_id' => $botUser->id,
            'status' => \App\Models\AdminRequest::STATUS_PENDING,
        ]);
        
        $settings = BotSettings::where('telegram_bot_id', $bot->id)->first();
        $message = $settings->msg_admin_request_sent ?? "Ваш запрос на роль администратора отправлен и ожидает рассмотрения.";
        $this->sendMessage($bot, $chatId, $message);
    }
    
    /**
     * Handle /status command - show user's tickets
     */
    private function handleStatusCommand(TelegramBot $bot, BotUser $botUser, int $chatId): void
    {
        $tickets = \App\Models\Ticket::where('bot_user_id', $botUser->id)->pluck('number')->toArray();
        
        if (empty($tickets)) {
            $this->sendMessage($bot, $chatId, "У вас пока нет номерков.\n\nОтправьте /start чтобы участвовать в розыгрыше!");
        } else {
            $ticketsList = implode(', ', $tickets);
            $this->sendMessage($bot, $chatId, "🎟 Ваши номерки: {$ticketsList}\n\nВсего: " . count($tickets) . " шт.");
        }
    }
    
    /**
     * Handle FSM for raffle
     */
    private function handleRaffleFSM(TelegramBot $bot, BotUser $botUser, int $chatId, array $message, BotSettings $settings): void
    {
        $text = $message['text'] ?? null;
        $photo = $message['photo'] ?? null;
        $document = $message['document'] ?? null;
        
        $state = $botUser->fsm_state;
        
        Log::info('Processing FSM state', ['state' => $state, 'user_id' => $botUser->id, 'has_text' => !empty($text)]);
        
        switch ($state) {
            case BotUser::STATE_WAIT_FIO:
                if ($text) {
                    $botUser->fio_encrypted = encrypt($text);
                    $botUser->fsm_state = BotUser::STATE_WAIT_PHONE;
                    $botUser->save();
                    
                    $msg = $settings->msg_ask_phone ?? "📱 Введите номер телефона в формате +7XXXXXXXXXX:";
                    $keyboard = $this->getBackCancelKeyboard();
                    $this->editOrSendMessage($bot, $chatId, $botUser->last_bot_message_id, $msg, $keyboard);
                }
                break;
                
            case BotUser::STATE_WAIT_PHONE:
                if ($text) {
                    // Basic phone validation
                    $phone = preg_replace('/[^0-9+]/', '', $text);
                    if (strlen($phone) >= 10) {
                        $botUser->phone_encrypted = encrypt($phone);
                        $botUser->fsm_state = BotUser::STATE_WAIT_INN;
                        $botUser->save();
                        
                        $msg = $settings->msg_ask_inn ?? "🔢 Введите ваш ИНН (10 или 12 цифр):";
                        $keyboard = $this->getBackCancelKeyboard();
                        $this->editOrSendMessage($bot, $chatId, $botUser->last_bot_message_id, $msg, $keyboard);
                    } else {
                        $this->sendMessage($bot, $chatId, "❌ Неверный формат телефона. Введите номер в формате +7XXXXXXXXXX:");
                    }
                }
                break;
                
            case BotUser::STATE_WAIT_INN:
                if ($text) {
                    $inn = preg_replace('/[^0-9]/', '', $text);
                    if (strlen($inn) == 10 || strlen($inn) == 12) {
                        $botUser->inn_encrypted = encrypt($inn);
                        $botUser->fsm_state = BotUser::STATE_CONFIRM_DATA;
                        $botUser->save();
                        
                        $this->showConfirmData($bot, $botUser, $chatId, $settings);
                    } else {
                        $this->sendMessage($bot, $chatId, "❌ ИНН должен содержать 10 или 12 цифр. Попробуйте ещё раз:");
                    }
                }
                break;
                
            case BotUser::STATE_SHOW_QR:
            case BotUser::STATE_WAIT_CHECK:
                // Handle check submission
                if ($photo || ($document && ($this->isImageDocument($document) || $this->isPdfDocument($document)))) {
                    $this->handleRaffleCheck($bot, $botUser, $chatId, $message, $settings);
                } else if ($text) {
                    $this->sendMessage($bot, $chatId, "📤 Отправьте фото чека или PDF-файл для подтверждения оплаты.");
                }
                break;
                
            case BotUser::STATE_PENDING_REVIEW:
                $msg = $settings->msg_check_received ?? "⏳ Ваш чек уже на проверке. Ожидайте результата.";
                $this->sendMessage($bot, $chatId, $msg);
                break;
                
            case BotUser::STATE_REJECTED:
                $msg = "Ваш предыдущий чек был отклонён. Отправьте новый чек или нажмите /start для начала.";
                $this->sendMessage($bot, $chatId, $msg);
                break;
                
            default:
                $this->sendMessage($bot, $chatId, "Отправьте /start чтобы начать участие в розыгрыше.");
                break;
        }
    }
    
    /**
     * Show confirm data screen
     */
    private function showConfirmData(TelegramBot $bot, BotUser $botUser, int $chatId, BotSettings $settings): void
    {
        $fio = $botUser->fio_encrypted ? decrypt($botUser->fio_encrypted) : 'Не указано';
        $phone = $botUser->phone_encrypted ? decrypt($botUser->phone_encrypted) : 'Не указан';
        $inn = $botUser->inn_encrypted ? decrypt($botUser->inn_encrypted) : 'Не указан';
        
        $msg = $settings->msg_confirm_data ?? "Проверьте введённые данные:\n\nФИО: {fio}\nТелефон: {phone}\nИНН: {inn}\n\nВсё верно?";
        $msg = str_replace('{fio}', $fio, $msg);
        $msg = str_replace('{phone}', $phone, $msg);
        $msg = str_replace('{inn}', $inn, $msg);
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✅ Подтвердить', 'callback_data' => 'confirm_data']],
                [['text' => '🔄 Заполнить заново', 'callback_data' => 'retry_data']],
                [['text' => '❌ Отмена', 'callback_data' => 'cancel']]
            ]
        ];
        
        $this->editOrSendMessage($bot, $chatId, $botUser->last_bot_message_id, $msg, $keyboard);
    }
    
    /**
     * Show QR code for payment
     */
    private function showQrCode(TelegramBot $bot, BotUser $botUser, int $chatId, BotSettings $settings): void
    {
        $qrPath = $settings->qr_image_path;
        
        if (!$qrPath || !Storage::disk('public')->exists($qrPath)) {
            Log::error('QR image not found', ['path' => $qrPath]);
            $this->sendMessage($bot, $chatId, "❌ QR-код временно недоступен. Обратитесь к администратору.");
            return;
        }
        
        $msg = $settings->msg_show_qr ?? "Оплатите {price} руб по QR-коду.\n\nНазначение платежа: {payment_description}\n\nПосле оплаты отправьте фото или PDF чека.";
        $msg = str_replace('{price}', number_format($settings->slot_price, 0, ',', ' '), $msg);
        $msg = str_replace('{payment_description}', $settings->payment_description ?? 'Оплата наклейки', $msg);
        
        // Delete old message and send photo
        if ($botUser->last_bot_message_id) {
            $this->deleteMessage($bot, $chatId, $botUser->last_bot_message_id);
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '◀️ Назад', 'callback_data' => 'back_to_confirm']],
                [['text' => '❌ Отмена', 'callback_data' => 'cancel']]
            ]
        ];
        
        $fullPath = Storage::disk('public')->path($qrPath);
        $result = $this->sendPhoto($bot, $chatId, $fullPath, $msg, $keyboard);
        
        if ($result && isset($result['message_id'])) {
            $botUser->update([
                'fsm_state' => BotUser::STATE_WAIT_CHECK,
                'last_bot_message_id' => $result['message_id']
            ]);
        }
    }
    
    /**
     * Handle check submission in raffle mode
     */
    private function handleRaffleCheck(TelegramBot $bot, BotUser $botUser, int $chatId, array $message, BotSettings $settings): void
    {
        $photo = $message['photo'] ?? null;
        $document = $message['document'] ?? null;
        
        $this->sendMessage($bot, $chatId, '⏳ Обрабатываю чек...');
        
        $userData = [
            'username' => $botUser->username,
            'first_name' => $botUser->first_name,
        ];
        
        $checkRecord = [
            'telegram_bot_id' => $bot->id,
            'chat_id' => $chatId,
            'username' => $userData['username'],
            'first_name' => $userData['first_name'],
            'bot_user_id' => $botUser->id,
            'review_status' => 'pending',
        ];
        
        try {
            $fileId = null;
            $isPdf = false;
            
            if ($photo) {
                $photoSizes = array_reverse($photo);
                $fileId = $photoSizes[0]['file_id'];
                $checkRecord['file_type'] = 'image';
            } elseif ($document) {
                $fileId = $document['file_id'];
                $isPdf = $this->isPdfDocument($document);
                $checkRecord['file_type'] = $isPdf ? 'pdf' : 'image';
            }
            
            if (!$fileId) {
                $this->sendMessage($bot, $chatId, '❌ Не удалось получить файл.');
                return;
            }
            
            // Get and download file
            $file = $this->getFile($bot, $fileId);
            if (!$file) {
                $this->sendMessage($bot, $chatId, '❌ Ошибка при получении файла.');
                return;
            }
            
            $filePath = $this->downloadFile($bot, $file['file_path']);
            if (!$filePath) {
                $this->sendMessage($bot, $chatId, '❌ Ошибка при загрузке файла.');
                return;
            }
            
            // === ПРОВЕРКА ДУБЛИКАТОВ ===
            
            // 1. Вычисляем хеш файла
            $fullFilePath = Storage::disk('local')->path($filePath);
            $fileHash = Check::calculateFileHash($fullFilePath);
            
            Log::info('Checking for duplicate check', [
                'file_hash' => $fileHash,
                'bot_id' => $bot->id,
            ]);
            
            // Process with OCR
            $checkData = $this->processCheckWithOCR($filePath, $isPdf);
            
            // 2. Извлекаем ID операции из текста чека
            $operationId = null;
            if ($checkData && isset($checkData['raw_text'])) {
                $operationId = Check::extractOperationId($checkData['raw_text']);
                Log::info('Extracted operation ID', ['operation_id' => $operationId]);
            }
            
            // 3. Генерируем уникальный ключ на основе суммы и даты
            $uniqueKey = null;
            if ($checkData) {
                $uniqueKey = Check::generateUniqueKey(
                    $checkData['amount'] ?? null,
                    $checkData['date'] ?? null
                );
                Log::info('Generated unique key', ['unique_key' => $uniqueKey]);
            }
            
            // 4. Проверяем на дубликат
            $duplicateOriginal = Check::findDuplicate($bot->id, $fileHash, $operationId, $uniqueKey);
            
            if ($duplicateOriginal) {
                Log::warning('Duplicate check detected', [
                    'original_check_id' => $duplicateOriginal->id,
                    'file_hash' => $fileHash,
                    'operation_id' => $operationId,
                    'unique_key' => $uniqueKey,
                ]);
                
                // Удаляем загруженный файл
                Storage::disk('local')->delete($filePath);
                
                // Уведомляем пользователя
                $duplicateMessage = $this->getDuplicateCheckMessage($settings, $duplicateOriginal);
                $this->sendMessage($bot, $chatId, $duplicateMessage);
                
                return;
            }
            
            // === КОНЕЦ ПРОВЕРКИ ДУБЛИКАТОВ ===
            
            // Получаем или создаём текущий розыгрыш
            $currentRaffle = Raffle::getOrCreateForBot($bot->id);
            
            // Очистка текста от проблемных символов для MySQL
            $rawText = null;
            if (isset($checkData['raw_text'])) {
                $rawText = $checkData['raw_text'];
                $rawText = mb_convert_encoding($rawText, 'UTF-8', 'UTF-8');
                $rawText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $rawText);
                $rawText = substr($rawText, 0, 5000);
            }
            
            // Очистка first_name
            $firstName = $userData['first_name'];
            if ($firstName) {
                $firstName = mb_convert_encoding($firstName, 'UTF-8', 'UTF-8');
                $firstName = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $firstName);
            }
            
            // Create check record
            $check = Check::create([
                'telegram_bot_id' => $bot->id,
                'raffle_id' => $currentRaffle->id,
                'chat_id' => $chatId,
                'username' => $userData['username'],
                'first_name' => $firstName,
                'bot_user_id' => $botUser->id,
                'file_path' => $filePath,
                'file_type' => $checkRecord['file_type'],
                'file_size' => $file['file_size'] ?? null,
                'file_hash' => $fileHash,
                'operation_id' => $operationId,
                'unique_key' => $uniqueKey,
                'is_duplicate' => false,
                'amount' => $checkData['amount'] ?? null,
                'check_date' => $checkData['date'] ?? null,
                'ocr_method' => $checkData['ocr_method'] ?? null,
                'raw_text' => $rawText,
                'status' => $checkData ? 'success' : 'failed',
                'amount_found' => isset($checkData['amount']),
                'date_found' => isset($checkData['date']),
                'review_status' => 'pending',
            ]);
            
            // Update user state
            $botUser->update(['fsm_state' => BotUser::STATE_PENDING_REVIEW]);
            
            // Send confirmation to user
            $msg = $settings->msg_check_received ?? "✅ Ваш чек принят и отправлен на проверку.\n\nМы уведомим вас о результате!";
            $this->sendMessage($bot, $chatId, $msg);
            
            // Notify admins
            $this->notifyAdminsAboutCheck($bot, $check, $checkData);
            
        } catch (\Exception $e) {
            Log::error('Error processing raffle check: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendMessage($bot, $chatId, '❌ Произошла ошибка при обработке чека. Попробуйте ещё раз.');
        }
    }
    
    /**
     * Get message for duplicate check
     */
    private function getDuplicateCheckMessage(BotSettings $settings, Check $originalCheck): string
    {
        // Определяем информацию о статусе оригинального чека
        $statusInfo = match ($originalCheck->review_status) {
            'approved' => "Данный чек был одобрен ранее и по нему уже выданы номерки.",
            'pending' => "Данный чек уже находится на проверке.\nДождитесь результата проверки или отправьте другой чек.",
            'rejected' => "Данный чек был ранее отклонён.\nЕсли вы считаете это ошибкой, обратитесь к администратору.",
            default => "Данный чек уже был отправлен ранее.",
        };
        
        // Используем настраиваемое сообщение или дефолтное
        return $settings->getMessage('check_duplicate', [
            'status_info' => $statusInfo,
        ]);
    }
    
    /**
     * Notify bot admins about new check
     */
    private function notifyAdminsAboutCheck(TelegramBot $bot, Check $check, ?array $checkData): void
    {
        // Get all admin users for this bot
        $admins = BotUser::where('telegram_bot_id', $bot->id)
            ->where('role', BotUser::ROLE_ADMIN)
            ->get();
        
        if ($admins->isEmpty()) {
            Log::warning('No admins to notify about check', ['check_id' => $check->id]);
            return;
        }
        
        $amount = $checkData['amount'] ?? 'Не определена';
        $date = $checkData['date'] ?? 'Не определена';
        $username = $check->username ? '@' . $check->username : 'Без username';
        
        $message = "🆕 Новый чек на проверку!\n\n" .
            "👤 Пользователь: {$username}\n" .
            "💰 Сумма: " . (is_numeric($amount) ? number_format($amount, 2, ',', ' ') . ' ₽' : $amount) . "\n" .
            "📅 Дата: {$date}\n" .
            "🆔 Check ID: {$check->id}\n\n" .
            "Откройте админ-панель для проверки.";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Одобрить', 'callback_data' => 'admin_approve_' . $check->id],
                    ['text' => '❌ Отклонить', 'callback_data' => 'admin_reject_' . $check->id]
                ],
                [['text' => '✏️ Редактировать', 'callback_data' => 'admin_edit_' . $check->id]]
            ]
        ];
        
        foreach ($admins as $admin) {
            try {
                // Send check file
                if ($check->file_path && Storage::disk('local')->exists($check->file_path)) {
                    $fullPath = Storage::disk('local')->path($check->file_path);
                    if ($check->file_type === 'pdf') {
                        $this->sendDocument($bot, $admin->telegram_user_id, $fullPath, $message, $keyboard);
                    } else {
                        $this->sendPhoto($bot, $admin->telegram_user_id, $fullPath, $message, $keyboard);
                    }
                } else {
                    $this->sendMessageWithKeyboard($bot, $admin->telegram_user_id, $message, $keyboard);
                }
            } catch (\Exception $e) {
                Log::error('Failed to notify admin', ['admin_id' => $admin->id, 'error' => $e->getMessage()]);
            }
        }
    }
    
    /**
     * Get back/cancel keyboard
     */
    private function getBackCancelKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '◀️ Назад', 'callback_data' => 'back']],
                [['text' => '❌ Отмена', 'callback_data' => 'cancel']]
            ]
        ];
    }

    /**
     * Handle /start command
     */
    private function handleStartCommand(TelegramBot $bot, int $chatId): void
    {
        Log::info('Handling /start command', ['bot_id' => $bot->id, 'chat_id' => $chatId]);
        
        // Используем приветственное сообщение из БД или дефолтное
        $welcomeMessage = $bot->getWelcomeMessageText();

        $this->sendMessage($bot, $chatId, $welcomeMessage);
    }

    /**
     * Handle photo
     */
    private function handlePhoto(TelegramBot $bot, int $chatId, array $photo, array $userData = []): void
    {
        // Send "processing" message
        $this->sendMessage($bot, $chatId, '⏳ Обрабатываю чек...');

        Log::info('Processing photo', [
            'chat_id' => $chatId,
            'photo_sizes' => count($photo),
            'sizes' => array_map(fn($p) => ['width' => $p['width'] ?? 0, 'height' => $p['height'] ?? 0, 'file_size' => $p['file_size'] ?? 0], $photo)
        ]);

        // Данные для сохранения в БД
        $checkRecord = [
            'telegram_bot_id' => $bot->id,
            'chat_id' => $chatId,
            'username' => $userData['username'] ?? null,
            'first_name' => $userData['first_name'] ?? null,
            'file_type' => 'image',
        ];

        try {
            // Используем ТОЛЬКО 2 самых больших размера фото
            // Маленькие фото (thumbnail) дают плохое качество OCR
            $photoSizes = array_reverse($photo); // Start with largest
            $photoSizes = array_slice($photoSizes, 0, 2); // Только 2 самых больших
            
            $checkData = null;
            $processedFiles = [];
            $lastFilePath = null;
            $lastFileSize = null;
            $bestRawText = null;
            $bestOcrMethod = null;

            foreach ($photoSizes as $index => $photoSize) {
                $fileId = $photoSize['file_id'];
                $width = $photoSize['width'] ?? 0;
                $height = $photoSize['height'] ?? 0;
                
                // Пропускаем слишком маленькие фото
                if ($width < 300 || $height < 300) {
                    Log::info("Skipping small photo", ['width' => $width, 'height' => $height]);
                    continue;
                }
                
                Log::info("Trying photo size {$index}", [
                    'file_id' => substr($fileId, 0, 20) . '...',
                    'width' => $width,
                    'height' => $height
                ]);
                
                // Get file from Telegram
                $file = $this->getFile($bot, $fileId);
                if (!$file) {
                    Log::warning("Failed to get file for photo size {$index}");
                    continue;
                }

                // Download file
                $filePath = $this->downloadFile($bot, $file['file_path']);
                if (!$filePath) {
                    Log::warning("Failed to download file for photo size {$index}");
                    continue;
                }

                Log::info("Downloaded file", ['path' => $filePath, 'size' => $file['file_size'] ?? 0]);
                $processedFiles[] = $filePath;
                $lastFilePath = $filePath;
                $lastFileSize = $file['file_size'] ?? null;

                // Process check using OCR
                Log::info("Starting OCR processing", ['file' => $filePath]);
                $checkData = $this->processCheckWithOCR($filePath, false);
                
                if ($checkData) {
                    Log::info("Check data successfully extracted!", ['check_data' => $checkData]);
                    
                    // Сохраняем успешный чек в БД
                    $this->saveCheckToDatabase($checkRecord, $checkData, $filePath, $lastFileSize, 'success');
                    
                    // Success! Clean up and return
                    foreach ($processedFiles as $pf) {
                        if ($pf !== $filePath) {
                            Storage::disk('local')->delete($pf);
                        }
                    }
                    $this->sendCheckResult($bot, $chatId, $checkData);
                    return;
                } else {
                    Log::warning("OCR extraction failed for photo size {$index}");
                    // НЕ переходим к меньшим фото - выходим из цикла
                    // Если большое фото не дало результат, меньшие тоже не дадут
                    break;
                }
            }

            // If we get here, all attempts failed
            Log::error("All OCR extraction attempts failed", [
                'photo_sizes_tried' => count($photoSizes),
                'files_processed' => count($processedFiles),
                'chat_id' => $chatId
            ]);
            
            // Сохраняем неуспешную попытку в БД
            $this->saveCheckToDatabase($checkRecord, null, $lastFilePath, $lastFileSize, 'failed');
            
            foreach ($processedFiles as $pf) {
                Storage::disk('local')->delete($pf);
            }
            
            $this->sendMessage($bot, $chatId, '❌ Не удалось распознать текст на чеке. Убедитесь, что фото четкое и текст хорошо виден. Попробуйте отправить другое фото или PDF.');
        } catch (\Exception $e) {
            Log::error('Error processing photo: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);
            $this->sendMessage($bot, $chatId, '❌ Произошла ошибка при обработке чека.');
        }
    }

    /**
     * Handle document (image file)
     */
    private function handleDocument(TelegramBot $bot, int $chatId, array $document, array $userData = []): void
    {
        $fileId = $document['file_id'];
        $isPdf = $this->isPdfDocument($document);

        // Send "processing" message
        $this->sendMessage($bot, $chatId, '⏳ Обрабатываю чек...');

        // Данные для сохранения в БД
        $checkRecord = [
            'telegram_bot_id' => $bot->id,
            'chat_id' => $chatId,
            'username' => $userData['username'] ?? null,
            'first_name' => $userData['first_name'] ?? null,
            'file_type' => $isPdf ? 'pdf' : 'image',
        ];

        try {
            // Get file from Telegram
            $file = $this->getFile($bot, $fileId);
            if (!$file) {
                $this->saveCheckToDatabase($checkRecord, null, null, null, 'failed');
                $this->sendMessage($bot, $chatId, '❌ Ошибка при получении файла.');
                return;
            }

            // Download file
            $filePath = $this->downloadFile($bot, $file['file_path']);
            if (!$filePath) {
                $this->saveCheckToDatabase($checkRecord, null, null, null, 'failed');
                $this->sendMessage($bot, $chatId, '❌ Ошибка при загрузке файла.');
                return;
            }

            $fileSize = $file['file_size'] ?? null;

            // Process check using OCR
            Log::info("Processing document with OCR", ['is_pdf' => $isPdf, 'file' => $filePath]);
            $checkData = $this->processCheckWithOCR($filePath, $isPdf);

            // Send result
            if ($checkData) {
                $this->saveCheckToDatabase($checkRecord, $checkData, $filePath, $fileSize, 'success');
                $this->sendCheckResult($bot, $chatId, $checkData);
            } else {
                $this->saveCheckToDatabase($checkRecord, null, $filePath, $fileSize, 'failed');
                $this->sendMessage($bot, $chatId, '❌ Не удалось распознать текст на чеке. Убедитесь, что фото четкое и текст хорошо виден. Попробуйте отправить другое фото или PDF.');
                Storage::disk('local')->delete($filePath);
            }
        } catch (\Exception $e) {
            Log::error('Error processing document: ' . $e->getMessage());
            $this->sendMessage($bot, $chatId, '❌ Произошла ошибка при обработке чека.');
        }
    }

    /**
     * Check if document is an image or PDF
     */
    private function isImageDocument(array $document): bool
    {
        $mimeType = $document['mime_type'] ?? '';
        return str_starts_with($mimeType, 'image/') || $mimeType === 'application/pdf';
    }

    /**
     * Check if document is PDF
     */
    private function isPdfDocument(array $document): bool
    {
        $mimeType = $document['mime_type'] ?? '';
        $fileName = $document['file_name'] ?? '';
        return $mimeType === 'application/pdf' || str_ends_with(strtolower($fileName), '.pdf');
    }

    /**
     * Сохранить чек в базу данных для статистики
     */
    private function saveCheckToDatabase(array $baseData, ?array $checkData, ?string $filePath, ?int $fileSize, string $status): void
    {
        try {
            $amountFound = isset($checkData['amount']) && $checkData['amount'] !== null;
            $dateFound = isset($checkData['date']) && $checkData['date'] !== null;
            
            // Определяем финальный статус
            if ($status === 'success') {
                if ($amountFound && $dateFound) {
                    $finalStatus = 'success';
                } elseif ($amountFound || $dateFound) {
                    $finalStatus = 'partial';
                } else {
                    $finalStatus = 'failed';
                }
            } else {
                $finalStatus = 'failed';
            }
            
            // Очистка текста от проблемных символов для MySQL
            $rawText = null;
            if (isset($checkData['raw_text'])) {
                $rawText = $checkData['raw_text'];
                // Убираем невалидные UTF-8 символы
                $rawText = mb_convert_encoding($rawText, 'UTF-8', 'UTF-8');
                // Убираем null bytes и другие проблемные символы
                $rawText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $rawText);
                $rawText = substr($rawText, 0, 5000);
            }
            
            // Очистка first_name
            $firstName = $baseData['first_name'] ?? null;
            if ($firstName) {
                $firstName = mb_convert_encoding($firstName, 'UTF-8', 'UTF-8');
                $firstName = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $firstName);
            }
            
            // Вычисляем данные для проверки дубликатов
            $fileHash = null;
            $operationId = null;
            $uniqueKey = null;
            
            if ($filePath) {
                $fullFilePath = Storage::disk('local')->path($filePath);
                if (file_exists($fullFilePath)) {
                    $fileHash = Check::calculateFileHash($fullFilePath);
                }
            }
            
            if ($rawText) {
                $operationId = Check::extractOperationId($rawText);
            }
            
            if ($checkData) {
                $uniqueKey = Check::generateUniqueKey(
                    $checkData['amount'] ?? null,
                    $checkData['date'] ?? null
                );
            }
            
            Check::create([
                'telegram_bot_id' => $baseData['telegram_bot_id'],
                'chat_id' => $baseData['chat_id'],
                'username' => $baseData['username'],
                'first_name' => $firstName,
                'file_path' => $filePath,
                'file_type' => $baseData['file_type'] ?? 'image',
                'file_size' => $fileSize,
                'file_hash' => $fileHash,
                'operation_id' => $operationId,
                'unique_key' => $uniqueKey,
                'is_duplicate' => false,
                'amount' => $checkData['amount'] ?? null,
                'currency' => $checkData['currency'] ?? 'RUB',
                'check_date' => isset($checkData['date']) ? $checkData['date'] : null,
                'ocr_method' => $checkData['ocr_method'] ?? null,
                'raw_text' => $rawText,
                'text_length' => $checkData['text_length'] ?? null,
                'readable_ratio' => $checkData['readable_ratio'] ?? null,
                'status' => $finalStatus,
                'amount_found' => $amountFound,
                'date_found' => $dateFound,
            ]);
            
            Log::info('Check saved to database', [
                'status' => $finalStatus,
                'amount_found' => $amountFound,
                'date_found' => $dateFound,
                'file_hash' => $fileHash ? substr($fileHash, 0, 16) . '...' : null,
                'operation_id' => $operationId,
                'unique_key' => $uniqueKey,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save check to database: ' . $e->getMessage());
        }
    }

    /**
     * Get file info from Telegram
     */
    private function getFile(TelegramBot $bot, string $fileId): ?array
    {
        try {
            $response = Http::get("https://api.telegram.org/bot{$bot->token}/getFile", [
                'file_id' => $fileId,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['result'] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error getting file: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Download file from Telegram
     */
    private function downloadFile(TelegramBot $bot, string $filePath): ?string
    {
        try {
            $url = "https://api.telegram.org/file/bot{$bot->token}/{$filePath}";
            $contents = Http::get($url)->body();

            $localPath = 'telegram/' . basename($filePath);
            Storage::disk('local')->put($localPath, $contents);

            return $localPath;
        } catch (\Exception $e) {
            Log::error('Error downloading file: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Process check using OCR - extract text and parse payment amount
     * Tries multiple OCR methods
     */
    private function processCheckWithOCR(string $filePath, bool $isPdf = false): ?array
    {
        try {
            $fullPath = Storage::disk('local')->path($filePath);

            // Convert PDF to image if needed
            if ($isPdf) {
                $fullPath = $this->convertPdfToImage($fullPath);
                if (!$fullPath) {
                    Log::error('Failed to convert PDF to image');
                    return null;
                }
            }

            // Try multiple OCR methods
            // Tesseract first (if installed) - local, fast, no API limits
            // Then remote Tesseract API, then external APIs as fallback
            // OCR methods - try multiple, use first successful result with enough text
            // Порядок OCR методов по приоритету:
            // 1. Remote Tesseract - лучше для русского текста, предобработка изображений
            // 2. Local Tesseract - быстрый, но обычно не установлен на shared hosting
            // 3. OCR.space - бесплатный fallback
            // 4. Google Vision - платный, но точный
            $ocrMethods = [
                'extractTextWithRemoteTesseract', // Remote VPS Tesseract API - лучший для русского
                'extractTextWithTesseract',       // Local - fastest, no limits
                'extractTextWithOCRspace',        // OCR.space - fallback
                'extractTextWithGoogleVision',    // Paid but reliable
            ];

            $extractedText = null;
            $usedOcrMethod = null;
            $ocrTextLength = null;
            $ocrReadableRatio = null;
            
            foreach ($ocrMethods as $method) {
                try {
                    Log::info("Trying OCR method: {$method}", ['file' => $fullPath]);
                    $text = $this->$method($fullPath);
                    if ($text && !empty(trim($text))) {
                        // Check text quality - should have reasonable amount of readable characters
                        $cleanText = trim($text);
                        $textLen = mb_strlen($cleanText, 'UTF-8');
                        
                        // Count readable characters (Cyrillic, Latin, digits)
                        $readableChars = preg_match_all('/[а-яА-ЯёЁa-zA-Z0-9]/u', $cleanText);
                        $readableRatio = $textLen > 0 ? $readableChars / $textLen : 0;
                        
                        Log::info("Text extracted using {$method}", [
                            'text_length' => $textLen,
                            'readable_chars' => $readableChars,
                            'readable_ratio' => round($readableRatio, 2),
                            'text_preview' => substr($cleanText, 0, 300)
                        ]);
                        
                        // Проверяем наличие ключевых слов чека/квитанции
                        $textLower = mb_strtolower($cleanText, 'UTF-8');
                        $hasKeywords = preg_match('/итого|сумма|перевод|оплата|чек|квитанция|банк|операци|комисси/ui', $textLower);
                        $hasAmount = preg_match('/\d{1,3}[\s\x{00A0}]?\d{3}|\d{4,}/u', $cleanText); // Число >= 1000 или 4+ цифры
                        
                        // Accept if: достаточно текста И есть признаки чека
                        // Для чеков ожидаем минимум 150 символов И ключевые слова
                        $isGoodText = ($textLen >= 150 && $readableRatio >= 0.50 && $hasKeywords) ||
                                      ($textLen >= 200 && $readableRatio >= 0.40) ||
                                      ($textLen >= 100 && $readableRatio >= 0.60 && $hasKeywords && $hasAmount);
                        
                        if ($isGoodText) {
                            $extractedText = $text;
                            $usedOcrMethod = $method;
                            $ocrTextLength = $textLen;
                            $ocrReadableRatio = $readableRatio;
                            Log::info("Text accepted from {$method}", [
                                'text_length' => $textLen,
                                'readable_ratio' => round($readableRatio, 2),
                                'has_keywords' => $hasKeywords,
                                'has_amount' => $hasAmount
                            ]);
                            break;
                        } else {
                            Log::warning("OCR text quality too low or missing keywords, trying next method", [
                                'method' => $method,
                                'text_length' => $textLen,
                                'readable_ratio' => round($readableRatio, 2),
                                'has_keywords' => $hasKeywords,
                                'has_amount' => $hasAmount
                            ]);
                        }
                    } else {
                        Log::debug("OCR method {$method} returned empty text");
                    }
                } catch (\Exception $e) {
                    Log::warning("OCR method {$method} failed: " . $e->getMessage(), [
                        'trace' => substr($e->getTraceAsString(), 0, 500)
                    ]);
                    continue;
                }
            }

            if (!$extractedText) {
                Log::error('All OCR methods failed', [
                    'file' => $fullPath,
                    'file_exists' => file_exists($fullPath),
                    'file_size' => file_exists($fullPath) ? filesize($fullPath) : 0
                ]);
                return null;
            }

            // Parse payment amount from text
            $checkData = $this->parsePaymentAmount($extractedText);
            
            if ($checkData) {
                // Добавляем информацию об OCR методе для статистики
                $checkData['ocr_method'] = $usedOcrMethod;
                $checkData['text_length'] = $ocrTextLength;
                $checkData['readable_ratio'] = $ocrReadableRatio;
                
                Log::info('Payment amount parsed successfully', ['check_data' => $checkData]);
                return $checkData;
            }

            Log::warning('Failed to parse payment amount from extracted text');
            return null;
        } catch (\Exception $e) {
            Log::error('Error processing check with OCR: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Process check - extract QR code and parse data (legacy method, kept for compatibility)
     * Tries multiple methods and image preprocessing variations
     */
    private function processCheck(string $filePath): ?array
    {
        try {
            $fullPath = Storage::disk('local')->path($filePath);

            // Try original image first (without preprocessing)
            Log::info("Attempting QR recognition on original image", ['file' => $filePath]);
            $result = $this->tryExtractQRCode($fullPath);
            if ($result) {
                Log::info("QR code successfully recognized from original image");
                return $result;
            }

            // Try with different preprocessing variations
            $preprocessVariations = [
                ['contrast' => 1, 'sharpen' => true, 'grayscale' => true],
                ['contrast' => 2, 'sharpen' => true, 'grayscale' => true],
                ['contrast' => 3, 'sharpen' => true, 'grayscale' => true],
                ['contrast' => 1, 'sharpen' => false, 'grayscale' => true],
                ['contrast' => 1, 'sharpen' => true, 'grayscale' => false],
                ['contrast' => 2, 'sharpen' => false, 'grayscale' => true],
                ['contrast' => 1, 'sharpen' => false, 'grayscale' => false],
                ['contrast' => 0, 'sharpen' => true, 'grayscale' => true], // Only sharpen and grayscale
            ];

            foreach ($preprocessVariations as $variationIndex => $variation) {
                Log::info("Trying preprocessing variation {$variationIndex}", $variation);
                $processedPath = $this->preprocessImageWithOptions($fullPath, $variation);
                if ($processedPath) {
                    Log::info("Processed image saved", ['processed' => $processedPath]);
                    $result = $this->tryExtractQRCode(Storage::disk('local')->path($processedPath));
                    if ($result) {
                        Log::info("QR code successfully recognized from preprocessed image (variation {$variationIndex})");
                        Storage::disk('local')->delete($processedPath);
                        return $result;
                    }
                    Storage::disk('local')->delete($processedPath);
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error processing check: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Try to extract QR code using all available methods
     */
    private function tryExtractQRCode(string $filePath): ?array
    {
        // Try multiple methods in order of reliability
        $methods = [
            'extractQRCodeWithAPI1',      // qrserver.com (most reliable)
            'extractQRCodeWithAPI6',      // qr-server.com (alternative)
            'extractQRCodeWithAPI7',      // qr-code-reader.com
            'extractQRCodeWithAPI8',      // qrcode.tec-it.com
            'extractQRCodeWithAPI3',      // api.qrserver alternative method
            'extractQRCodeWithAPI2',      // goqr.me (may have DNS issues)
            'extractQRCodeWithAPI4',      // api4free.com (may have DNS issues)
            'extractQRCodeWithAPI5',      // qr-code-reader.p.rapidapi.com (if key available)
            'extractQRCodeWithZxing',     // zxing (if available)
            'extractQRCodeWithPython',    // Python pyzbar (if available)
        ];

        foreach ($methods as $method) {
            try {
                Log::debug("Trying method: {$method}");
                $qrData = $this->$method($filePath);
                if ($qrData && !empty(trim($qrData))) {
                    Log::info("QR code extracted using {$method}", [
                        'data_length' => strlen($qrData),
                        'data_preview' => substr($qrData, 0, 100)
                    ]);
                    $parsed = $this->parseCheckData($qrData);
                    if ($parsed) {
                        Log::info("Check data parsed successfully", ['check_data' => $parsed]);
                        return $parsed;
                    } else {
                        Log::warning("QR data extracted but parsing failed", ['qr_data' => substr($qrData, 0, 200)]);
                    }
                }
            } catch (\Exception $e) {
                Log::debug("Method {$method} failed: " . $e->getMessage());
                continue;
            }
        }

        return null;
    }

    /**
     * Preprocess image with specific options
     */
    private function preprocessImageWithOptions(string $sourcePath, array $options): ?string
    {
        try {
            if (!extension_loaded('gd') && !extension_loaded('imagick')) {
                return null;
            }

            $imageInfo = getimagesize($sourcePath);
            if (!$imageInfo) {
                return null;
            }

            $mimeType = $imageInfo['mime'];
            $processedPath = 'telegram/processed_' . uniqid() . '.jpg';

            if (extension_loaded('imagick')) {
                return $this->preprocessWithImagickOptions($sourcePath, $processedPath, $options);
            } elseif (extension_loaded('gd')) {
                return $this->preprocessWithGDOptions($sourcePath, $processedPath, $mimeType, $options);
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Image preprocessing with options failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Preprocess image with Imagick using specific options
     */
    private function preprocessWithImagickOptions(string $sourcePath, string $targetPath, array $options): ?string
    {
        try {
            $image = new \Imagick($sourcePath);
            
            if ($options['grayscale'] ?? true) {
                $image->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
            }
            
            // Normalize
            $image->normalizeImage();
            
            // Contrast
            $contrast = $options['contrast'] ?? 1;
            for ($i = 0; $i < $contrast; $i++) {
                $image->contrastImage(1);
            }
            
            // Sharpen
            if ($options['sharpen'] ?? true) {
                $image->sharpenImage(0, 1);
            }
            
            // Save
            $image->setImageFormat('jpg');
            $image->setImageCompressionQuality(95);
            $image->writeImage(Storage::disk('local')->path($targetPath));
            $image->destroy();

            return $targetPath;
        } catch (\Exception $e) {
            Log::debug('Imagick preprocessing with options failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Preprocess image with GD using specific options
     */
    private function preprocessWithGDOptions(string $sourcePath, string $targetPath, string $mimeType, array $options): ?string
    {
        try {
            // Load image
            switch ($mimeType) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($sourcePath);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($sourcePath);
                    break;
                case 'image/gif':
                    $image = imagecreatefromgif($sourcePath);
                    break;
                default:
                    return null;
            }

            if (!$image) {
                return null;
            }

            // Grayscale
            if ($options['grayscale'] ?? true) {
                imagefilter($image, IMG_FILTER_GRAYSCALE);
            }
            
            // Contrast
            $contrast = $options['contrast'] ?? 1;
            for ($i = 0; $i < $contrast; $i++) {
                imagefilter($image, IMG_FILTER_CONTRAST, -20);
            }
            
            // Sharpen
            if ($options['sharpen'] ?? true) {
                $sharpen = [
                    [-1, -1, -1],
                    [-1, 16, -1],
                    [-1, -1, -1]
                ];
                imageconvolution($image, $sharpen, 8, 0);
            }

            // Save
            $targetFullPath = Storage::disk('local')->path($targetPath);
            imagejpeg($image, $targetFullPath, 95);
            imagedestroy($image);

            return $targetPath;
        } catch (\Exception $e) {
            Log::debug('GD preprocessing with options failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Preprocess image to improve QR code recognition
     */
    private function preprocessImage(string $filePath): ?string
    {
        try {
            if (!extension_loaded('gd') && !extension_loaded('imagick')) {
                return null; // No image processing available
            }

            $fullPath = Storage::disk('local')->path($filePath);
            $imageInfo = getimagesize($fullPath);
            
            if (!$imageInfo) {
                return null;
            }

            $mimeType = $imageInfo['mime'];
            $processedPath = 'telegram/processed_' . uniqid() . '.jpg';

            if (extension_loaded('imagick')) {
                return $this->preprocessWithImagick($fullPath, $processedPath);
            } elseif (extension_loaded('gd')) {
                return $this->preprocessWithGD($fullPath, $processedPath, $mimeType);
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Image preprocessing failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Preprocess image with Imagick
     */
    private function preprocessWithImagick(string $sourcePath, string $targetPath): ?string
    {
        try {
            $image = new \Imagick($sourcePath);
            
            // Enhance contrast
            $image->normalizeImage();
            
            // Sharpen
            $image->sharpenImage(0, 1);
            
            // Convert to grayscale for better QR recognition
            $image->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
            
            // Increase contrast
            $image->contrastImage(1);
            
            // Save
            $image->setImageFormat('jpg');
            $image->setImageCompressionQuality(95);
            $image->writeImage(Storage::disk('local')->path($targetPath));
            $image->destroy();

            return $targetPath;
        } catch (\Exception $e) {
            Log::debug('Imagick preprocessing failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Preprocess image with GD
     */
    private function preprocessWithGD(string $sourcePath, string $targetPath, string $mimeType): ?string
    {
        try {
            // Load image based on type
            switch ($mimeType) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($sourcePath);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($sourcePath);
                    break;
                case 'image/gif':
                    $image = imagecreatefromgif($sourcePath);
                    break;
                default:
                    return null;
            }

            if (!$image) {
                return null;
            }

            // Convert to grayscale
            imagefilter($image, IMG_FILTER_GRAYSCALE);
            
            // Enhance contrast
            imagefilter($image, IMG_FILTER_CONTRAST, -20);
            
            // Sharpen
            $sharpen = [
                [-1, -1, -1],
                [-1, 16, -1],
                [-1, -1, -1]
            ];
            imageconvolution($image, $sharpen, 8, 0);

            // Save
            $targetFullPath = Storage::disk('local')->path($targetPath);
            imagejpeg($image, $targetFullPath, 95);
            imagedestroy($image);

            return $targetPath;
        } catch (\Exception $e) {
            Log::debug('GD preprocessing failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using zxing (requires Java and zxing installed)
     */
    private function extractQRCodeWithZxing(string $filePath): ?string
    {
        try {
            // Check if zxing is available
            $zxingPath = exec('which zxing 2>/dev/null') ?: exec('which java 2>/dev/null');
            if (!$zxingPath) {
                return null;
            }

            // Try to decode QR code
            $command = "zxing --decode {$filePath} 2>/dev/null";
            $output = exec($command, $outputArray, $returnCode);

            if ($returnCode === 0 && !empty($output)) {
                return $output;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract QR code using PHP library (placeholder - implement if library is installed)
     */
    private function extractQRCodeWithLibrary(string $filePath): ?string
    {
        // TODO: Implement if QR code library is installed
        // Example: using simple-qrcode or other library
        return null;
    }

    /**
     * Extract QR code using API 1 (qrserver.com)
     */
    private function extractQRCodeWithAPI1(string $filePath): ?string
    {
        try {
            $url = 'https://api.qrserver.com/v1/read-qr-code/';
            $fileContents = file_get_contents($filePath);
            $fileSize = strlen($fileContents);
            
            Log::debug('Trying API1 (qrserver.com)', [
                'file_size' => $fileSize,
                'file_path' => $filePath
            ]);
            
            $response = Http::timeout(30)
                ->attach('file', $fileContents, basename($filePath))
                ->post($url);

            Log::debug('API1 response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => substr($response->body(), 0, 500)
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data[0]['symbol'][0]['data'])) {
                    $qrData = $data[0]['symbol'][0]['data'];
                    if (!empty(trim($qrData))) {
                        Log::info('API1 success', ['qr_data_length' => strlen($qrData)]);
                        return $qrData;
                    }
                }
                // Check for errors in response
                if (isset($data[0]['symbol'][0]['error'])) {
                    Log::warning('API1 error', ['error' => $data[0]['symbol'][0]['error']]);
                }
            } else {
                Log::warning('API1 request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 200)
                ]);
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('API1 (qrserver) exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 2 (goqr.me)
     */
    private function extractQRCodeWithAPI2(string $filePath): ?string
    {
        try {
            $url = 'https://api.goqr.me/api/read-qr-code/';
            
            $response = Http::timeout(30)
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['symbols'][0]['data'])) {
                    $qrData = $data['symbols'][0]['data'];
                    if (!empty(trim($qrData))) {
                        return $qrData;
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API2 (goqr) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 3 (alternative method with different parameters)
     */
    private function extractQRCodeWithAPI3(string $filePath): ?string
    {
        try {
            // Try qrserver.com with different approach
            $url = 'https://api.qrserver.com/v1/read-qr-code/';
            
            $response = Http::timeout(30)
                ->attach('file', file_get_contents($filePath), basename($filePath), [
                    'Content-Type' => 'image/jpeg'
                ])
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data[0]['symbol'][0]['data'])) {
                    $qrData = $data[0]['symbol'][0]['data'];
                    if (!empty(trim($qrData))) {
                        return $qrData;
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API3 (alternative) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 4 (api4free.com)
     */
    private function extractQRCodeWithAPI4(string $filePath): ?string
    {
        try {
            $url = 'https://api4free.com/api/qr-reader';
            
            $response = Http::timeout(30)
                ->attach('image', file_get_contents($filePath), basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['data']) && !empty(trim($data['data']))) {
                    return $data['data'];
                }
                if (isset($data['text']) && !empty(trim($data['text']))) {
                    return $data['text'];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API4 (api4free) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 5 (rapidapi - requires API key, but we try anyway)
     */
    private function extractQRCodeWithAPI5(string $filePath): ?string
    {
        try {
            // This API might require a key, but we try without it first
            $url = 'https://qr-code-reader.p.rapidapi.com/api/v1/read-qr-code';
            
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-RapidAPI-Key' => env('RAPIDAPI_KEY', ''),
                    'X-RapidAPI-Host' => 'qr-code-reader.p.rapidapi.com'
                ])
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data[0]['data']) && !empty(trim($data[0]['data']))) {
                    return $data[0]['data'];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API5 (rapidapi) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 6 (qr-server.com - alternative to qrserver.com)
     */
    private function extractQRCodeWithAPI6(string $filePath): ?string
    {
        try {
            $url = 'https://qr-server.com/api/read-qr-code/';
            
            $response = Http::timeout(30)
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data[0]['symbol'][0]['data']) && !empty(trim($data[0]['symbol'][0]['data']))) {
                    return $data[0]['symbol'][0]['data'];
                }
                if (isset($data['result']) && !empty(trim($data['result']))) {
                    return $data['result'];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API6 (qr-server) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 7 (qr-code-reader.com)
     */
    private function extractQRCodeWithAPI7(string $filePath): ?string
    {
        try {
            $url = 'https://api.qr-code-reader.com/v1/read-qr-code';
            
            $response = Http::timeout(30)
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['data']) && !empty(trim($data['data']))) {
                    return $data['data'];
                }
                if (isset($data['text']) && !empty(trim($data['text']))) {
                    return $data['text'];
                }
                if (isset($data[0]['data']) && !empty(trim($data[0]['data']))) {
                    return $data[0]['data'];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API7 (qr-code-reader) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using API 8 (qrcode.tec-it.com)
     */
    private function extractQRCodeWithAPI8(string $filePath): ?string
    {
        try {
            // Try base64 encoding
            $base64Image = base64_encode(file_get_contents($filePath));
            $url = 'https://qrcode.tec-it.com/API/QRCode';
            
            $response = Http::timeout(30)
                ->asForm()
                ->post($url, [
                    'data' => 'data:image/jpeg;base64,' . $base64Image
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['value']) && !empty(trim($data['value']))) {
                    return $data['value'];
                }
                if (isset($data['data']) && !empty(trim($data['data']))) {
                    return $data['data'];
                }
            }

            // Try alternative method with file upload
            $response = Http::timeout(30)
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post($url);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['value']) && !empty(trim($data['value']))) {
                    return $data['value'];
                }
                if (isset($data['data']) && !empty(trim($data['data']))) {
                    return $data['data'];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('API8 (qrcode.tec-it) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code using Python with pyzbar (if available)
     */
    private function extractQRCodeWithPython(string $filePath): ?string
    {
        try {
            // Check if Python and pyzbar are available
            $pythonCheck = exec('python3 --version 2>&1') ?: exec('python --version 2>&1');
            if (!$pythonCheck) {
                return null;
            }

            // Create temporary Python script
            $scriptPath = sys_get_temp_dir() . '/qr_decode_' . uniqid() . '.py';
            $script = <<<'PYTHON'
import sys
from pyzbar.pyzbar import decode
from PIL import Image

try:
    img = Image.open(sys.argv[1])
    decoded_objects = decode(img)
    if decoded_objects:
        print(decoded_objects[0].data.decode('utf-8'))
        sys.exit(0)
    else:
        sys.exit(1)
except Exception as e:
    print(f"Error: {e}", file=sys.stderr)
    sys.exit(1)
PYTHON;

            file_put_contents($scriptPath, $script);

            // Run Python script
            $command = "python3 {$scriptPath} {$filePath} 2>&1";
            $output = exec($command, $outputArray, $returnCode);

            // Clean up
            if (file_exists($scriptPath)) {
                unlink($scriptPath);
            }

            if ($returnCode === 0 && !empty(trim($output))) {
                return trim($output);
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Python pyzbar failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Convert PDF to image for OCR processing
     */
    private function convertPdfToImage(string $pdfPath): ?string
    {
        try {
            // Check if Imagick is available and supports PDF
            if (!extension_loaded('imagick')) {
                Log::warning('Imagick not available for PDF conversion');
                return null;
            }

            $image = new \Imagick();
            // Use higher DPI for better text quality
            $image->setResolution(450, 450);
            $image->readImage($pdfPath . '[0]'); // Read first page only
            
            // Convert to RGB colorspace for better OCR
            $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);
            
            // Enhance image quality for OCR
            $image->setImageFormat('png'); // PNG preserves text better than JPG
            $image->setImageCompressionQuality(95);
            
            // Convert to grayscale for OCR
            $image->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
            
            // Improve contrast
            $image->normalizeImage();
            $image->contrastImage(true);
            
            // Sharpen text edges
            $image->sharpenImage(0, 1.5);
            
            // Resize if too large
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            Log::info('PDF image dimensions', ['width' => $width, 'height' => $height]);
            if ($width > 3500 || $height > 3500) {
                $image->scaleImage(3500, 3500, true);
            }
            
            $imagePath = 'telegram/pdf_' . uniqid() . '.png';
            $image->writeImage(Storage::disk('local')->path($imagePath));
            $image->destroy();

            Log::info('PDF converted to image', [
                'pdf_path' => $pdfPath,
                'image_path' => $imagePath,
                'resolution' => '450 DPI',
                'format' => 'PNG grayscale'
            ]);

            return Storage::disk('local')->path($imagePath);
        } catch (\Exception $e) {
            Log::error('PDF conversion failed: ' . $e->getMessage(), [
                'trace' => substr($e->getTraceAsString(), 0, 500)
            ]);
            return null;
        }
    }


    /**
     * Extract text using OCR.space API (free tier available)
     */
    private function extractTextWithOCRspace(string $filePath): ?string
    {
        try {
            $apiKey = env('OCR_SPACE_API_KEY', 'helloworld'); // Free tier key
            $fileSize = filesize($filePath);
            
            // Skip if file is too large (over 1MB)
            if ($fileSize > 1024 * 1024) {
                Log::warning('File too large for OCR.space', ['file_size' => $fileSize]);
                return null;
            }
            
            Log::info('Calling OCR.space API', ['file' => $filePath, 'file_size' => $fileSize]);
            
            // Try base64 method first (faster and more reliable)
            $fileContents = file_get_contents($filePath);
            $base64Image = base64_encode($fileContents);
            
            $response = Http::timeout(30)
                ->asForm()
                ->post('https://api.ocr.space/parse/imagebase64', [
                    'apikey' => $apiKey,
                    'base64Image' => 'data:image/jpeg;base64,' . $base64Image,
                    'language' => 'rus',
                    'isOverlayRequired' => 'false',
                    'detectOrientation' => 'true',
                ]);

            Log::info('OCR.space API response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body_preview' => substr($response->body(), 0, 500)
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('OCR.space response data', ['has_parsed_results' => isset($data['ParsedResults'])]);
                
                if (isset($data['ParsedResults'][0]['ParsedText'])) {
                    $text = trim($data['ParsedResults'][0]['ParsedText']);
                    Log::info('OCR.space extracted text', ['text_length' => strlen($text), 'text_preview' => substr($text, 0, 200)]);
                    return $text;
                }
                
                // Check for errors
                if (isset($data['ErrorMessage'])) {
                    Log::warning('OCR.space error', ['error' => $data['ErrorMessage']]);
                }
            } else {
                Log::warning('OCR.space API request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500)
                ]);
            }

            // If base64 method failed, try multipart as fallback
            if (!$response->successful() || !isset($response->json()['ParsedResults'])) {
                Log::info('Trying OCR.space with multipart method');
                $response = Http::timeout(30)
                    ->asMultipart()
                    ->attach('file', $fileContents, basename($filePath))
                    ->post('https://api.ocr.space/parse/image', [
                        'apikey' => $apiKey,
                        'language' => 'rus',
                        'isOverlayRequired' => 'false',
                        'detectOrientation' => 'true',
                    ]);
            }

            Log::info('OCR.space API response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body_preview' => substr($response->body(), 0, 500)
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('OCR.space response data', ['has_parsed_results' => isset($data['ParsedResults'])]);
                
                if (isset($data['ParsedResults'][0]['ParsedText'])) {
                    $text = trim($data['ParsedResults'][0]['ParsedText']);
                    Log::info('OCR.space extracted text', ['text_length' => strlen($text), 'text_preview' => substr($text, 0, 200)]);
                    return $text;
                }
                
                // Check for errors
                if (isset($data['ErrorMessage'])) {
                    Log::warning('OCR.space error', ['error' => $data['ErrorMessage']]);
                }
            } else {
                Log::warning('OCR.space API request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500)
                ]);
            }

            return null;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('OCR.space API timeout/connection error: ' . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            Log::error('OCR.space API exception: ' . $e->getMessage(), [
                'trace' => substr($e->getTraceAsString(), 0, 500)
            ]);
            return null;
        }
    }

    /**
     * Extract text using Tesseract OCR (requires tesseract installed)
     */
    private function extractTextWithTesseract(string $filePath): ?string
    {
        try {
            // Check if tesseract is available
            // First try system-wide installation
            $tesseractPath = exec('which tesseract 2>/dev/null');
            
            // If not found, try local installation in project
            if (!$tesseractPath) {
                $projectLocalTesseract = base_path('local/tesseract/bin/tesseract');
                if (file_exists($projectLocalTesseract) && is_executable($projectLocalTesseract)) {
                    $tesseractPath = $projectLocalTesseract;
                    Log::info('Using local Tesseract from project directory');
                }
            }
            
            // If still not found, try home directory
            if (!$tesseractPath) {
                $homeTesseract = getenv('HOME') . '/tesseract-local/bin/tesseract';
                if (file_exists($homeTesseract) && is_executable($homeTesseract)) {
                    $tesseractPath = $homeTesseract;
                    Log::info('Using local Tesseract from home directory');
                }
            }
            
            if (!$tesseractPath) {
                Log::debug('Tesseract not found - install system-wide with: sudo apt-get install tesseract-ocr tesseract-ocr-rus');
                Log::debug('Or install locally in project/local/tesseract/ or ~/tesseract-local/');
                return null;
            }

            Log::info('Using Tesseract OCR', [
                'tesseract_path' => $tesseractPath,
                'file' => $filePath,
                'file_size' => filesize($filePath)
            ]);

            // Preprocess image for better OCR results
            $preprocessedPath = $this->preprocessImageForTesseract($filePath);
            if ($preprocessedPath) {
                // Convert relative path to full path
                $imageToProcess = Storage::disk('local')->path($preprocessedPath);
            } else {
                $imageToProcess = $filePath;
            }

            // Check if Russian and English languages are available
            $langsOutput = exec(escapeshellarg($tesseractPath) . ' --list-langs 2>&1', $langsArray, $langsReturnCode);
            $hasRussian = false;
            $hasEnglish = false;
            if ($langsReturnCode === 0) {
                foreach ($langsArray as $line) {
                    $line = trim($line);
                    if ($line === 'rus') {
                        $hasRussian = true;
                    }
                    if ($line === 'eng') {
                        $hasEnglish = true;
                    }
                }
            }

            // Build language parameter - use both Russian and English if available
            $langParam = '';
            if ($hasRussian && $hasEnglish) {
                $langParam = '-l rus+eng';
            } elseif ($hasRussian) {
                $langParam = '-l rus';
            } elseif ($hasEnglish) {
                $langParam = '-l eng';
            } else {
                Log::warning('No language packs found for Tesseract. Install with: sudo apt-get install tesseract-ocr-rus tesseract-ocr-eng');
            }

            // Run tesseract with optimized parameters for document recognition
            // --psm 6: Assume a single uniform block of text (good for receipts)
            // --psm 4: Assume a single column of text of variable sizes
            // --oem 3: Default, based on what is available (LSTM if available)
            $outputPath = sys_get_temp_dir() . '/tesseract_' . uniqid();
            
            // Try PSM 6 first (single uniform block) - best for receipts
            $command = escapeshellarg($tesseractPath) . " " . escapeshellarg($imageToProcess) . " " . escapeshellarg($outputPath) . 
                       " {$langParam} --psm 6 --oem 3 2>&1";
            
            Log::debug('Running Tesseract command', ['command' => $command]);
            
            exec($command, $output, $returnCode);

            $text = '';
            if ($returnCode === 0 && file_exists($outputPath . '.txt')) {
                $text = file_get_contents($outputPath . '.txt');
                unlink($outputPath . '.txt');
            }

            // If first attempt failed or returned little text, try PSM 4 (single column)
            if (empty(trim($text)) || strlen(trim($text)) < 10) {
                Log::debug('Tesseract PSM 6 returned little text, trying PSM 4');
                $command = escapeshellarg($tesseractPath) . " " . escapeshellarg($imageToProcess) . " " . escapeshellarg($outputPath) . 
                           " {$langParam} --psm 4 --oem 3 2>&1";
                exec($command, $output, $returnCode);
                
                if ($returnCode === 0 && file_exists($outputPath . '.txt')) {
                    $text = file_get_contents($outputPath . '.txt');
                    unlink($outputPath . '.txt');
                }
            }

            // Clean up preprocessed image if it was created
            if ($preprocessedPath && $preprocessedPath !== $filePath) {
                $fullPreprocessedPath = Storage::disk('local')->path($preprocessedPath);
                if (file_exists($fullPreprocessedPath)) {
                    @unlink($fullPreprocessedPath);
                }
            }

            if (!empty(trim($text))) {
                Log::info('Tesseract extracted text successfully', [
                    'text_length' => strlen($text),
                    'text_preview' => substr($text, 0, 200)
                ]);
                return trim($text);
            } else {
                Log::debug('Tesseract returned empty text', [
                    'return_code' => $returnCode,
                    'output' => implode("\n", array_slice($output, 0, 5))
                ]);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Tesseract OCR exception: ' . $e->getMessage(), [
                'trace' => substr($e->getTraceAsString(), 0, 500)
            ]);
            return null;
        }
    }

    /**
     * Preprocess image specifically for Tesseract OCR
     * Optimizes image for better text recognition
     */
    private function preprocessImageForTesseract(string $filePath): ?string
    {
        try {
            // Check if Imagick is available
            if (!extension_loaded('imagick')) {
                Log::debug('Imagick not available for image preprocessing');
                return null;
            }

            $image = new \Imagick($filePath);
            
            // Get image dimensions
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            
            // Resize if image is too large (Tesseract works better with 300-400 DPI)
            // If image is smaller than 1000px, scale it up
            if ($width < 1000 || $height < 1000) {
                $scale = max(1000 / $width, 1000 / $height);
                $newWidth = (int)($width * $scale);
                $newHeight = (int)($height * $scale);
                $image->resizeImage($newWidth, $newHeight, \Imagick::FILTER_LANCZOS, 1);
                Log::debug('Image resized for Tesseract', [
                    'original' => "{$width}x{$height}",
                    'new' => "{$newWidth}x{$newHeight}"
                ]);
            }
            
            // Convert to grayscale (better for OCR)
            $image->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
            
            // Enhance contrast using normalize
            $image->normalizeImage();
            
            // Increase contrast further
            $image->contrastImage(1);
            
            // Sharpen image for better text recognition
            $image->sharpenImage(0, 1.5);
            
            // Apply adaptive threshold (binarization) - very important for OCR
            // This converts image to black and white, removing noise
            $image->thresholdImage(0.5);
            
            // Reduce noise
            $image->despeckleImage();
            
            // Save preprocessed image
            $processedPath = 'telegram/preprocessed_' . uniqid() . '.jpg';
            $image->setImageFormat('jpg');
            $image->setImageCompressionQuality(95);
            $image->writeImage(Storage::disk('local')->path($processedPath));
            $image->destroy();

            Log::debug('Image preprocessed for Tesseract', ['processed_path' => $processedPath]);
            return $processedPath;
        } catch (\Exception $e) {
            Log::debug('Image preprocessing for Tesseract failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract text using remote Tesseract API (on VPS)
     */
    private function extractTextWithRemoteTesseract(string $filePath): ?string
    {
        try {
            $remoteUrl = env('TESSERACT_REMOTE_URL', 'http://89.169.39.244:8080/');
            $remoteToken = env('TESSERACT_REMOTE_TOKEN');
            
            if (!$remoteUrl || !$remoteToken) {
                Log::debug('Remote Tesseract API not configured');
                return null;
            }
            
            $fileContents = file_get_contents($filePath);
            $base64Image = base64_encode($fileContents);
            
            Log::info('Calling remote Tesseract API', [
                'url' => $remoteUrl,
                'file' => $filePath,
                'file_size' => strlen($fileContents)
            ]);
            
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $remoteToken,
                    'Content-Type' => 'application/json'
                ])
                ->post($remoteUrl, [
                    'image' => $base64Image,
                    'langs' => 'rus+eng'
                ]);
            
            Log::info('Remote Tesseract API response', [
                'status' => $response->status(),
                'successful' => $response->successful()
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['success']) && $data['success'] && !empty($data['text'])) {
                    $text = trim($data['text']);
                    
                    // Проверяем и исправляем кодировку если нужно
                    // Иногда UTF-8 текст приходит как Latin1
                    if (!mb_check_encoding($text, 'UTF-8')) {
                        $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
                        Log::debug('Converted text encoding from ISO-8859-1 to UTF-8');
                    }
                    
                    Log::info('Remote Tesseract extracted text', [
                        'text_length' => strlen($text),
                        'text_preview' => substr($text, 0, 200)
                    ]);
                    return $text;
                } else {
                    Log::debug('Remote Tesseract returned empty text');
                }
            } else {
                Log::warning('Remote Tesseract API request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500)
                ]);
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('Remote Tesseract OCR exception: ' . $e->getMessage(), [
                'trace' => substr($e->getTraceAsString(), 0, 500)
            ]);
            return null;
        }
    }

    /**
     * Extract text using Google Cloud Vision API
     */
    private function extractTextWithGoogleVision(string $filePath): ?string
    {
        try {
            $apiKey = env('GOOGLE_VISION_API_KEY');
            if (!$apiKey) {
                Log::debug('Google Vision API key not configured');
                return null;
            }

            $base64Image = base64_encode(file_get_contents($filePath));
            
            $response = Http::timeout(30)
                ->post("https://vision.googleapis.com/v1/images:annotate?key={$apiKey}", [
                    'requests' => [
                        [
                            'image' => [
                                'content' => $base64Image
                            ],
                            'features' => [
                                ['type' => 'TEXT_DETECTION']
                            ]
                        ]
                    ]
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['responses'][0]['textAnnotations'][0]['description'])) {
                    return trim($data['responses'][0]['textAnnotations'][0]['description']);
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Google Vision OCR failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse payment amount from extracted text
     */
    private function parsePaymentAmount(string $text): ?array
    {
        try {
            Log::info('Parsing payment amount from text', [
                'text_length' => strlen($text),
                'text_preview' => substr($text, 0, 500)
            ]);
            
            // Store original text for debugging
            $originalText = $text;
            
            // Normalize text - preserve line breaks for better context
            $text = preg_replace('/\r\n|\r/', "\n", $text);
            $textLower = mb_strtolower($text, 'UTF-8');
            
            // Extract date first to exclude it from amount search
            $date = null;
            
            // Russian month names mapping (including common OCR errors)
            $russianMonths = [
                'января' => '01', 'январь' => '01', 'янв' => '01',
                'февраля' => '02', 'февраль' => '02', 'фев' => '02',
                'фезраля' => '02', 'фезрапя' => '02', 'феврапя' => '02', // OCR errors
                'марта' => '03', 'март' => '03', 'мар' => '03',
                'апреля' => '04', 'апрель' => '04', 'апр' => '04',
                'anреля' => '04', 'апрепя' => '04', // OCR errors
                'мая' => '05', 'май' => '05',
                'июня' => '06', 'июнь' => '06', 'июн' => '06',
                'июля' => '07', 'июль' => '07', 'июл' => '07',
                'августа' => '08', 'август' => '08', 'авг' => '08',
                'аигуста' => '08', 'авгyста' => '08', // OCR errors
                'сентября' => '09', 'сентябрь' => '09', 'сен' => '09',
                'октября' => '10', 'октябрь' => '10', 'окт' => '10',
                'ноября' => '11', 'ноябрь' => '11', 'ноя' => '11',
                'декабря' => '12', 'декабрь' => '12', 'дек' => '12',
            ];
            
            // Try Russian month format first: "3 февраля 2026 в 14:38" or "3 февраля 2026"
            $monthPattern = implode('|', array_keys($russianMonths));
            
            Log::debug('Searching for Russian date pattern', [
                'month_pattern_length' => strlen($monthPattern),
                'text_sample' => mb_substr($text, 0, 500)
            ]);
            
            // Pattern: "3 февраля 2026 в 14:38" or "3 февраля 2026 14:38:00"
            if (preg_match('/(\d{1,2})\s+(' . $monthPattern . ')\s+(\d{4})(?:\s+(?:в\s+)?(\d{1,2}):(\d{2})(?::(\d{2}))?)?/ui', $text, $matches)) {
                $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $monthName = mb_strtolower($matches[2], 'UTF-8');
                $month = $russianMonths[$monthName] ?? '01';
                $year = $matches[3];
                
                $dateStr = "{$year}-{$month}-{$day}";
                
                if (isset($matches[4]) && isset($matches[5])) {
                    $hour = str_pad($matches[4], 2, '0', STR_PAD_LEFT);
                    $minute = $matches[5];
                    $dateStr .= " {$hour}:{$minute}";
                    if (isset($matches[6])) {
                        $dateStr .= ":{$matches[6]}";
                    }
                }
                
                $date = $dateStr;
                Log::info('Parsed Russian month date', ['date' => $date, 'match' => $matches[0]]);
            } else {
                Log::debug('Russian month date pattern not matched');
            }
            
            // Try "сегодня в HH:MM" or "вчера в HH:MM" format
            if (!$date) {
                if (preg_match('/сегодня\s+(?:в\s+)?(\d{1,2}):(\d{2})/ui', $text, $matches)) {
                    $today = date('Y-m-d');
                    $hour = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $minute = $matches[2];
                    $date = "{$today} {$hour}:{$minute}";
                    Log::debug('Parsed "today" date', ['date' => $date, 'match' => $matches[0]]);
                } elseif (preg_match('/вчера\s+(?:в\s+)?(\d{1,2}):(\d{2})/ui', $text, $matches)) {
                    $yesterday = date('Y-m-d', strtotime('-1 day'));
                    $hour = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $minute = $matches[2];
                    $date = "{$yesterday} {$hour}:{$minute}";
                    Log::debug('Parsed "yesterday" date', ['date' => $date, 'match' => $matches[0]]);
                }
            }
            
            // If no date found, try numeric patterns
            if (!$date) {
                $datePatterns = [
                    '/(\d{2})[\.\/](\d{2})[\.\/](\d{4})\s+(\d{2}):(\d{2}):(\d{2})/u', // 03.02.2026 10:14:31
                    '/(\d{2})[\.\/](\d{2})[\.\/](\d{4})\s+(\d{2}):(\d{2})/u', // 03.02.2026 10:14
                    '/(\d{2})[\.\/](\d{2})[\.\/](\d{4})/u', // 03.02.2026
                    '/(\d{4})[\.\/-](\d{2})[\.\/-](\d{2})/u', // 2026-02-03
                ];

                foreach ($datePatterns as $pattern) {
                    if (preg_match($pattern, $text, $matches)) {
                        try {
                            if (count($matches) >= 4) {
                                if (strlen($matches[1]) === 4) {
                                    // YYYY-MM-DD format
                                    $dateStr = "{$matches[1]}-{$matches[2]}-{$matches[3]}";
                                } else {
                                    // DD.MM.YYYY format
                                    $dateStr = "{$matches[3]}-{$matches[2]}-{$matches[1]}";
                                }
                                
                                if (isset($matches[4]) && isset($matches[5])) {
                                    $dateStr .= " {$matches[4]}:{$matches[5]}";
                                    if (isset($matches[6])) {
                                        $dateStr .= ":{$matches[6]}";
                                    }
                                }
                                
                                $date = $dateStr;
                                // Remove date from text to avoid matching it as amount
                                $text = preg_replace($pattern, '', $text);
                                $textLower = mb_strtolower($text, 'UTF-8');
                                break;
                            }
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                }
            }
            
            // ---- Amount extraction with scoring (prevents picking INN/account numbers) ----
            $amount = null;

            // Keywords that indicate payment amounts (including Sberbank-specific)
            $keywords = [
                'итого', 'сумма', 'к оплате', 'всего',
                'сумма в валюте карты', 'сумма в валюте операции',
                'сумма в валюте', 'в валюте карты', 'в валюте операции',
                'оплата', 'платёж', 'платеж'
            ];
            $badContextWords = [
                'инн', 'бик', 'кпп', 'огрн', 'р/с', 'счет', 'счёт', 
                'идентификатор', 'сбп', 'телефон',
                'код авторизации', 'авторизации', 'квитанция №', 'квитанции',
            ];
            
            // Normalize spaces in text for better number matching (convert all whitespace to single space)
            $textNormalized = preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}\r\n]+/u', ' ', $text);
            
            // Fix common OCR errors: replace letter O with digit 0 in number contexts
            // "25 ООО" -> "25 000", "1О ООО" -> "10 000"
            $textNormalized = preg_replace_callback(
                '/(\d+)\s*([ОоOo]+)\s*([ОоOoрРеЕ₽])/u',
                function ($m) {
                    $zeros = preg_replace('/[ОоOo]/u', '0', $m[2]);
                    return $m[1] . ' ' . $zeros . ' ' . $m[3];
                },
                $textNormalized
            );
            // Also fix standalone "ООО" after numbers: "25 ООО р" -> "25 000 р"
            $textNormalized = preg_replace('/(\d+)\s+[ОоOo]{3}\s+([рРеЕ₽Pp])/u', '$1 000 $2', $textNormalized);
            
            // ==========================================
            // ПОИСК СУММЫ - улучшенная логика
            // ==========================================
            // OCR часто путает ₽ с "е", "e", "P", "р", "R", "Р"
            $currencyPattern = '[₽РрPpеeЕER]';
            
            // Нормализуем текст для поиска
            $textForSearch = $text; // Оригинальный текст с переносами
            $textOneLine = preg_replace('/[\r\n]+/', ' ', $text); // Текст в одну строку
            
            Log::debug('Searching for amount in text', [
                'text_length' => mb_strlen($text),
                'first_500_chars' => mb_substr($textOneLine, 0, 500)
            ]);
            
            $directAmount = null;
            
            // 1. Ищем паттерн "Итого X XXX ₽" или "Итого\nX XXX ₽"
            // Поддерживаем разные форматы чисел: "10 000", "10000", "10,000.00"
            $amountRegex = '(\d{1,3}(?:[\s\x{00A0}]+\d{3})*(?:[.,]\d{2})?|\d+(?:[.,]\d{2})?)';
            
            $directPatterns = [
                // Итого + число + валюта (может быть на разных строках)
                '/итого[^\d]{0,30}' . $amountRegex . '\s*' . $currencyPattern . '/ui',
                // Сумма + число + валюта
                '/сумма[^\d]{0,30}' . $amountRegex . '\s*' . $currencyPattern . '/ui',
                // Число + валюта рядом с "Итого" в пределах 50 символов
                '/итого.{0,50}?' . $amountRegex . '\s*' . $currencyPattern . '/uis',
            ];
            
            foreach ($directPatterns as $pattern) {
                if (preg_match($pattern, $textOneLine, $match)) {
                    $numStr = preg_replace('/[\s\x{00A0}]+/u', '', $match[1]);
                    $numStr = str_replace(',', '.', $numStr);
                    if (is_numeric($numStr)) {
                        $val = (float) $numStr;
                        if ($val >= 100 && $val < 10000000) {
                            $directAmount = $val;
                            Log::info('Found direct amount with Итого/Сумма', [
                                'pattern' => $pattern,
                                'amount' => $directAmount,
                                'raw_match' => mb_substr($match[0], 0, 100)
                            ]);
                            break;
                        }
                    }
                }
            }
            
            // 2. Если не нашли с ключевыми словами - ищем все суммы с валютой
            if (!$directAmount) {
                $allAmountsWithCurrency = [];
                
                // Паттерн: число (формат "X XXX" или "XXXXX") + символ валюты
                if (preg_match_all('/' . $amountRegex . '\s*' . $currencyPattern . '/ui', $textOneLine, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                    foreach ($matches as $match) {
                        $numStr = preg_replace('/[\s\x{00A0}]+/u', '', $match[1][0]);
                        $numStr = str_replace(',', '.', $numStr);
                        if (is_numeric($numStr)) {
                            $val = (float) $numStr;
                            // Исключаем слишком маленькие и слишком большие значения
                            if ($val >= 100 && $val < 10000000) {
                                $allAmountsWithCurrency[] = [
                                    'amount' => $val,
                                    'raw' => $match[0][0],
                                    'pos' => $match[0][1]
                                ];
                            }
                        }
                    }
                }
                
                Log::debug('All amounts with currency found', ['amounts' => $allAmountsWithCurrency]);
                
                // Выбираем наибольшую сумму (обычно это итого)
                if (!empty($allAmountsWithCurrency)) {
                    usort($allAmountsWithCurrency, fn($a, $b) => $b['amount'] <=> $a['amount']);
                    $directAmount = $allAmountsWithCurrency[0]['amount'];
                    Log::info('Selected largest amount with currency', [
                        'amount' => $directAmount,
                        'raw' => $allAmountsWithCurrency[0]['raw']
                    ]);
                }
            }
            
            // 3. Если всё ещё не нашли - пробуем без символа валюты, но рядом с ключевыми словами
            if (!$directAmount) {
                $keywordPatterns = [
                    '/итого[:\s]+' . $amountRegex . '/ui',
                    '/сумма[:\s]+' . $amountRegex . '/ui',
                    '/к\s*оплате[:\s]+' . $amountRegex . '/ui',
                    '/всего[:\s]+' . $amountRegex . '/ui',
                ];
                
                foreach ($keywordPatterns as $pattern) {
                    if (preg_match($pattern, $textOneLine, $match)) {
                        $numStr = preg_replace('/[\s\x{00A0}]+/u', '', $match[1]);
                        $numStr = str_replace(',', '.', $numStr);
                        if (is_numeric($numStr)) {
                            $val = (float) $numStr;
                            if ($val >= 100 && $val < 10000000) {
                                $directAmount = $val;
                                Log::info('Found amount near keyword (no currency symbol)', [
                                    'pattern' => $pattern,
                                    'amount' => $directAmount,
                                    'raw_match' => $match[0]
                                ]);
                                break;
                            }
                        }
                    }
                }
            }

            // Find all numeric candidates (with optional thousands separators and decimals)
            // Pattern matches: "10 000", "10000", "1 234 567", "123,45", "123.45"
            if (preg_match_all('/\d{1,3}(?:[\s]\d{3})+(?:[.,]\d{2})?|\d+(?:[.,]\d{2})?/u', $textNormalized, $numMatches, PREG_OFFSET_CAPTURE)) {
                $candidates = [];

                foreach ($numMatches[0] as [$rawNum, $pos]) {
                    $rawNumTrim = trim($rawNum);

                    // Skip obvious dates (02.02, 03.02.2026, 03022016)
                    if (preg_match('/^\d{1,2}[.\/]\d{1,2}([.\/]\d{2,4})?$/u', $rawNumTrim)) {
                        continue;
                    }
                    if (preg_match('/^\d{8}$/u', $rawNumTrim)) {
                        continue;
                    }
                    
                    // Skip numbers that are part of time format (HH:MM)
                    $charAfter = substr($textNormalized, $pos + strlen($rawNumTrim), 1);
                    $charBefore = $pos > 0 ? substr($textNormalized, $pos - 1, 1) : '';
                    if ($charAfter === ':' || $charBefore === ':') {
                        continue;
                    }
                    
                    // Skip numbers that look like part of receipt/transaction numbers (sequences of digits with dashes)
                    $contextAround = substr($textNormalized, max(0, $pos - 10), strlen($rawNumTrim) + 20);
                    if (preg_match('/\d+-\d+-\d+/', $contextAround)) {
                        continue;
                    }

                    // Normalize number - remove spaces and convert comma to dot
                    $normalized = preg_replace('/[\s\x{00A0}]+/u', '', $rawNumTrim);
                    $normalized = str_replace(',', '.', $normalized);

                    if (!is_numeric($normalized)) {
                        continue;
                    }

                    $val = (float) $normalized;
                    if ($val < 1 || $val > 1000000) {
                        continue;
                    }

                    // Context window around number
                    $winStart = max(0, $pos - 80);
                    $winLen = min(strlen($textNormalized) - $winStart, 160);
                    $context = mb_strtolower(substr($textNormalized, $winStart, $winLen), 'UTF-8');

                    // Reject if near known non-amount fields
                    $isBad = false;
                    foreach ($badContextWords as $w) {
                        if (str_contains($context, $w)) {
                            $isBad = true;
                            break;
                        }
                    }
                    if ($isBad) {
                        continue;
                    }

                    // Currency proximity - check if ₽/Р immediately follows the number (within 3 chars)
                    $afterClose = substr($textNormalized, $pos + strlen($rawNumTrim), 5);
                    $hasCurrencyClose = (bool) preg_match('/^\s*[₽РрPp]/ui', $afterClose);
                    
                    // Also check broader context for currency
                    $after = substr($textNormalized, $pos, 30);
                    $before = substr($textNormalized, max(0, $pos - 30), 30);
                    $hasCurrencyBroad = (bool) preg_match('/(₽|руб)/ui', $after) || (bool) preg_match('/(₽|руб)/ui', $before);

                    // Keyword proximity scoring
                    $score = 0;
                    
                    // Strong bonus for currency immediately after number
                    if ($hasCurrencyClose) {
                        $score += 10;
                    } elseif ($hasCurrencyBroad) {
                        $score += 3;
                    }
                    
                    // Strong bonus for key receipt keywords
                    if (str_contains($context, 'итого')) {
                        $score += 15;
                    }
                    if (str_contains($context, 'сумма') && !str_contains($context, 'комисси')) {
                        $score += 12;
                    }
                    foreach ($keywords as $kw) {
                        if (str_contains($context, $kw)) {
                            $score += 3;
                        }
                    }

                    // Prefer larger reasonable amounts (receipts usually > 50)
                    if ($val >= 100) {
                        $score += 3;
                    } elseif ($val >= 50) {
                        $score += 2;
                    } elseif ($val >= 10) {
                        $score += 1;
                    }
                    
                    // Penalize very small numbers (likely to be dates/times/counts)
                    if ($val < 25 && !$hasCurrencyClose) {
                        $score -= 5;
                    }
                    
                    if (preg_match('/^\d{6,}$/u', $normalized) && !$hasCurrencyClose) {
                        // large raw number without currency is suspicious (like INN/account)
                        $score -= 4;
                    }

                    $candidates[] = [
                        'amount' => $val,
                        'raw' => $rawNumTrim,
                        'pos' => $pos,
                        'score' => $score,
                        'has_currency' => $hasCurrencyClose || $hasCurrencyBroad,
                    ];
                }

                if (!empty($candidates)) {
                    usort($candidates, function ($a, $b) {
                        // score desc, then amount desc
                        $cmp = ($b['score'] <=> $a['score']);
                        if ($cmp !== 0) return $cmp;
                        return $b['amount'] <=> $a['amount'];
                    });

                    $best = $candidates[0];
                    
                    // Minimum score threshold - if too low, don't trust the result
                    // Score of 10+ means we found keywords like "итого" or "сумма" near the number
                    $minScoreThreshold = 8;
                    
                    Log::info('Amount selected by scoring', [
                        'amount' => $best['amount'],
                        'raw' => $best['raw'],
                        'score' => $best['score'],
                        'has_currency' => $best['has_currency'],
                        'min_threshold' => $minScoreThreshold,
                        'top3' => array_slice($candidates, 0, 3),
                    ]);
                    
                    if ($best['score'] >= $minScoreThreshold) {
                        $amount = $best['amount'];
                    } else {
                        Log::warning('Amount score too low, result unreliable', [
                            'best_score' => $best['score'],
                            'threshold' => $minScoreThreshold,
                            'best_amount' => $best['amount']
                        ]);
                    }
                }
            }
            
            // Приоритет: directAmount (найдена рядом с ключевыми словами) > scored amount
            // directAmount более надежна т.к. привязана к контексту (Итого, Сумма и т.д.)
            if ($directAmount) {
                Log::info('Using direct pattern amount (highest priority)', [
                    'direct_amount' => $directAmount,
                    'scored_amount' => $amount
                ]);
                $amount = $directAmount;
            }
            
            // Если directAmount не найдена, используем scored amount только если score достаточно высок
            // Если и score низкий - всё равно возвращаем null

            if ($amount) {
                Log::info('Final amount selected', ['amount' => $amount, 'date' => $date]);
                return [
                    'sum' => $amount,
                    'amount' => $amount,
                    'date' => $date,
                    'currency' => 'RUB',
                    'raw_text' => substr($originalText, 0, 500),
                ];
            }

            Log::warning('No reliable amount found in text');
            return null;
        } catch (\Exception $e) {
            Log::error('Error parsing payment amount: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse check data from QR code string
     * Russian fiscal receipt format (ФНС)
     */
    private function parseCheckData(string $qrData): ?array
    {
        try {
            // Russian fiscal receipt QR code format:
            // t=YYYYMMDDTHHMM&s=SUM&fn=FN&i=FPD&fp=FP&n=OPERATION_TYPE
            
            $params = [];
            parse_str($qrData, $params);

            if (empty($params)) {
                return null;
            }

            $checkData = [
                'date' => $this->parseDate($params['t'] ?? null),
                'sum' => $params['s'] ?? null,
                'fn' => $params['fn'] ?? null, // Fiscal number
                'fpd' => $params['i'] ?? null, // Fiscal document number
                'fp' => $params['fp'] ?? null, // Fiscal sign
                'operation_type' => $params['n'] ?? null,
                'raw_data' => $qrData,
            ];

            return $checkData;
        } catch (\Exception $e) {
            Log::error('Error parsing check data: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse date from fiscal receipt format
     */
    private function parseDate(?string $dateString): ?string
    {
        if (!$dateString) {
            return null;
        }

        try {
            // Format: YYYYMMDDTHHMM
            if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})$/', $dateString, $matches)) {
                return "{$matches[1]}-{$matches[2]}-{$matches[3]} {$matches[4]}:{$matches[5]}";
            }
            return $dateString;
        } catch (\Exception $e) {
            return $dateString;
        }
    }

    /**
     * Send check result to user
     */
    private function sendCheckResult(TelegramBot $bot, int $chatId, array $checkData): void
    {
        $message = "✅ Чек успешно обработан!\n\n";
        
        // Handle date
        $date = $checkData['date'] ?? null;
        if ($date) {
            $message .= "📅 Дата: {$date}\n";
        }
        
        // Handle amount (new OCR format) or sum (old QR format)
        $amount = $checkData['amount'] ?? $checkData['sum'] ?? null;
        if ($amount !== null) {
            // If sum is greater than 10000, it's likely in kopecks, otherwise in rubles
            if (is_numeric($amount) && $amount > 10000 && !isset($checkData['amount'])) {
                $amountFormatted = number_format($amount / 100, 2, '.', ' ') . ' ₽';
            } else {
                $amountFormatted = number_format((float)$amount, 2, '.', ' ') . ' ₽';
            }
            $message .= "💰 Сумма: {$amountFormatted}\n";
        }
        
        // Handle fiscal data (only for QR code receipts)
        if (isset($checkData['fn'])) {
            $message .= "🏪 ФН: " . ($checkData['fn'] ?? 'Не указан') . "\n";
        }
        if (isset($checkData['fpd'])) {
            $message .= "📄 ФД: " . ($checkData['fpd'] ?? 'Не указан') . "\n";
        }
        if (isset($checkData['fp'])) {
            $message .= "🔐 ФП: " . ($checkData['fp'] ?? 'Не указан') . "\n";
        }

        $this->sendMessage($bot, $chatId, $message);
    }

    /**
     * Handle callback query (button clicks)
     */
    private function handleCallbackQuery(TelegramBot $bot, array $callbackQuery): void
    {
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $telegramUserId = $callbackQuery['from']['id'];
        $data = $callbackQuery['data'] ?? '';

        // Answer callback query
        Http::post("https://api.telegram.org/bot{$bot->token}/answerCallbackQuery", [
            'callback_query_id' => $callbackQuery['id'],
        ]);

        Log::info('Handling callback query', ['data' => $data, 'user_id' => $telegramUserId]);

        // Check if raffle mode
        $botSettings = BotSettings::where('telegram_bot_id', $bot->id)->first();
        if (!$botSettings || !$botSettings->is_active) {
            return; // No raffle mode, ignore callbacks
        }

        // Get bot user
        $botUser = BotUser::where('telegram_bot_id', $bot->id)
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        if (!$botUser) {
            return;
        }

        // Handle navigation
        switch ($data) {
            case 'cancel':
            case 'home':
                $botUser->update(['fsm_state' => BotUser::STATE_IDLE, 'last_bot_message_id' => null]);
                // Удаляем inline сообщение
                $this->deleteMessage($bot, $chatId, $messageId);
                // Отправляем сообщение с постоянным меню
                $this->sendMessage($bot, $chatId, "❌ Действие отменено.\n\nИспользуйте меню для навигации или нажмите 🏠 Главная для начала.");
                return;

            case 'back':
                $this->handleBackButton($bot, $botUser, $chatId, $messageId, $botSettings);
                return;

            case 'participate':
                // Start data collection
                $botUser->update(['fsm_state' => BotUser::STATE_WAIT_FIO]);
                $msg = $botSettings->msg_ask_fio ?? "📝 Введите ваше ФИО (Фамилия Имя Отчество):";
                $keyboard = $this->getBackCancelKeyboard();
                $this->editMessageText($bot, $chatId, $messageId, $msg, $keyboard);
                $botUser->update(['last_bot_message_id' => $messageId]);
                return;

            case 'confirm_data':
                // Show QR code
                $this->showQrCode($bot, $botUser, $chatId, $botSettings);
                return;

            case 'retry_data':
                // Reset data and start over
                $botUser->update([
                    'fio_encrypted' => null,
                    'phone_encrypted' => null,
                    'inn_encrypted' => null,
                    'fsm_state' => BotUser::STATE_WAIT_FIO
                ]);
                $msg = $botSettings->msg_ask_fio ?? "📝 Введите ваше ФИО (Фамилия Имя Отчество):";
                $keyboard = $this->getBackCancelKeyboard();
                $this->editMessageText($bot, $chatId, $messageId, $msg, $keyboard);
                return;

            case 'back_to_confirm':
                $botUser->update(['fsm_state' => BotUser::STATE_CONFIRM_DATA]);
                $this->showConfirmData($bot, $botUser, $chatId, $botSettings);
                return;
                
            case 'send_check_again':
                $botUser->update(['fsm_state' => BotUser::STATE_WAIT_CHECK]);
                $msg = $botSettings->msg_wait_check ?? "📤 Отправьте фото чека или PDF-файл для подтверждения оплаты.";
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '❌ Отмена', 'callback_data' => 'cancel']]
                    ]
                ];
                $this->editMessageText($bot, $chatId, $messageId, $msg, $keyboard);
                return;
        }

        // Handle admin callbacks (approve/reject checks)
        if (str_starts_with($data, 'admin_approve_')) {
            $checkId = (int) str_replace('admin_approve_', '', $data);
            $this->handleAdminApproveCheck($bot, $botUser, $chatId, $messageId, $checkId, $botSettings);
            return;
        }

        if (str_starts_with($data, 'admin_reject_')) {
            $checkId = (int) str_replace('admin_reject_', '', $data);
            $this->handleAdminRejectCheck($bot, $botUser, $chatId, $messageId, $checkId, $botSettings);
            return;
        }
    }

    /**
     * Handle back button navigation
     */
    private function handleBackButton(TelegramBot $bot, BotUser $botUser, int $chatId, int $messageId, BotSettings $settings): void
    {
        $state = $botUser->fsm_state;
        $keyboard = $this->getBackCancelKeyboard();

        switch ($state) {
            case BotUser::STATE_WAIT_PHONE:
                $botUser->update(['fsm_state' => BotUser::STATE_WAIT_FIO]);
                $msg = $settings->msg_ask_fio ?? "📝 Введите ваше ФИО (Фамилия Имя Отчество):";
                $this->editMessageText($bot, $chatId, $messageId, $msg, $keyboard);
                break;

            case BotUser::STATE_WAIT_INN:
                $botUser->update(['fsm_state' => BotUser::STATE_WAIT_PHONE]);
                $msg = $settings->msg_ask_phone ?? "📱 Введите номер телефона в формате +7XXXXXXXXXX:";
                $this->editMessageText($bot, $chatId, $messageId, $msg, $keyboard);
                break;

            case BotUser::STATE_CONFIRM_DATA:
                $botUser->update(['fsm_state' => BotUser::STATE_WAIT_INN]);
                $msg = $settings->msg_ask_inn ?? "🔢 Введите ваш ИНН (10 или 12 цифр):";
                $this->editMessageText($bot, $chatId, $messageId, $msg, $keyboard);
                break;

            default:
                // Go to welcome
                $this->handleRaffleStart($bot, $botUser, $chatId, $settings);
                break;
        }
    }

    /**
     * Handle admin approve check via Telegram
     */
    private function handleAdminApproveCheck(TelegramBot $bot, BotUser $admin, int $chatId, int $messageId, int $checkId, BotSettings $settings): void
    {
        if (!$admin->isAdmin()) {
            $this->sendMessage($bot, $chatId, "❌ У вас нет прав для этого действия.");
            return;
        }

        $check = Check::with('botUser')->find($checkId);
        if (!$check) {
            $this->editMessageText($bot, $chatId, $messageId, "❌ Чек не найден.");
            return;
        }

        if ($check->review_status !== 'pending') {
            $this->editMessageText($bot, $chatId, $messageId, "⚠️ Этот чек уже был обработан.");
            return;
        }

        $amount = $check->admin_edited_amount ?? $check->amount;
        if (!$amount || $amount < $settings->slot_price) {
            $this->editMessageText($bot, $chatId, $messageId, "❌ Сумма ({$amount} ₽) меньше стоимости одного места ({$settings->slot_price} ₽).\n\nИспользуйте админ-панель для редактирования суммы.");
            return;
        }

        $ticketsCount = floor($amount / $settings->slot_price);
        $availableSlots = $settings->getAvailableSlotsCount();

        if ($ticketsCount > $availableSlots) {
            $this->editMessageText($bot, $chatId, $messageId, "❌ Недостаточно мест. Доступно: {$availableSlots}, требуется: {$ticketsCount}");
            return;
        }

        // Issue tickets
        $issuedTickets = [];
        for ($i = 0; $i < $ticketsCount; $i++) {
            $ticket = \App\Models\Ticket::where('telegram_bot_id', $bot->id)
                ->whereNull('bot_user_id')
                ->orderBy('number')
                ->first();

            if ($ticket) {
                $ticket->update([
                    'bot_user_id' => $check->bot_user_id,
                    'check_id' => $check->id,
                    'issued_at' => now(),
                ]);
                $issuedTickets[] = $ticket->number;
            }
        }

        // Update check
        $check->update([
            'review_status' => 'approved',
            'tickets_count' => count($issuedTickets),
        ]);

        // Update user state
        if ($check->botUser) {
            $check->botUser->update(['fsm_state' => BotUser::STATE_APPROVED]);

            // Notify user
            $ticketsList = implode(', ', $issuedTickets);
            $userMsg = $settings->msg_check_approved ?? "🎉 Платёж подтверждён!\n\nВаши номерки: {tickets}\n\nУдачи в розыгрыше!";
            $userMsg = str_replace('{tickets}', $ticketsList, $userMsg);
            $this->sendMessage($bot, $check->botUser->telegram_user_id, $userMsg);
        }

        // Update admin message
        $this->editMessageText($bot, $chatId, $messageId, "✅ Чек #{$checkId} одобрен!\n\nВыдано номерков: " . count($issuedTickets) . "\nНомера: " . implode(', ', $issuedTickets));

        // Log action
        \App\Models\AdminActionLog::create([
            'telegram_bot_id' => $bot->id,
            'admin_user_id' => $admin->id,
            'action_type' => 'check_approved_telegram',
            'target_type' => 'check',
            'target_id' => $checkId,
            'new_data' => ['tickets' => $issuedTickets],
        ]);
    }

    /**
     * Handle admin reject check via Telegram
     */
    private function handleAdminRejectCheck(TelegramBot $bot, BotUser $admin, int $chatId, int $messageId, int $checkId, BotSettings $settings): void
    {
        if (!$admin->isAdmin()) {
            $this->sendMessage($bot, $chatId, "❌ У вас нет прав для этого действия.");
            return;
        }

        $check = Check::with('botUser')->find($checkId);
        if (!$check) {
            $this->editMessageText($bot, $chatId, $messageId, "❌ Чек не найден.");
            return;
        }

        if ($check->review_status !== 'pending') {
            $this->editMessageText($bot, $chatId, $messageId, "⚠️ Этот чек уже был обработан.");
            return;
        }

        // Update check
        $check->update(['review_status' => 'rejected']);

        // Update user state
        if ($check->botUser) {
            $check->botUser->update(['fsm_state' => BotUser::STATE_REJECTED]);

            // Notify user
            $userMsg = $settings->msg_check_rejected ?? "❌ Чек не принят.\n\nПроверьте оплату и отправьте чек повторно.";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔄 Отправить заново', 'callback_data' => 'send_check_again']],
                    [['text' => '🏠 В начало', 'callback_data' => 'home']]
                ]
            ];
            $this->sendMessageWithKeyboard($bot, $check->botUser->telegram_user_id, $userMsg, $keyboard);
        }

        // Update admin message
        $this->editMessageText($bot, $chatId, $messageId, "❌ Чек #{$checkId} отклонён.");

        // Log action
        \App\Models\AdminActionLog::create([
            'telegram_bot_id' => $bot->id,
            'admin_user_id' => $admin->id,
            'action_type' => 'check_rejected_telegram',
            'target_type' => 'check',
            'target_id' => $checkId,
        ]);
    }

    /**
     * Получить Reply Keyboard для постоянного меню
     */
    private function getReplyKeyboard(): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => TelegramMenuService::BTN_HOME],
                    ['text' => TelegramMenuService::BTN_ABOUT],
                ],
                [
                    ['text' => TelegramMenuService::BTN_MY_TICKETS],
                    ['text' => TelegramMenuService::BTN_SUPPORT],
                ],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /**
     * Send message to user with Reply Keyboard
     */
    private function sendMessage(TelegramBot $bot, int $chatId, string $text, bool $withMenu = true): ?array
    {
        try {
            Log::info('Sending message to Telegram', [
                'bot_id' => $bot->id,
                'chat_id' => $chatId,
                'text_length' => strlen($text)
            ]);
            
            $params = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ];
            
            // Добавляем постоянную клавиатуру если нужно
            if ($withMenu) {
                $params['reply_markup'] = json_encode($this->getReplyKeyboard());
            }
            
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$bot->token}/sendMessage", $params);

            if ($response->successful()) {
                Log::info('Message sent successfully');
                return $response->json('result');
            } else {
                Log::error('Failed to send message', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error sending message: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
        return null;
    }

    /**
     * Send message with inline keyboard (Reply Keyboard stays visible)
     */
    private function sendMessageWithKeyboard(TelegramBot $bot, int $chatId, string $text, array $keyboard): ?array
    {
        try {
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$bot->token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode($keyboard),
                ]);

            if ($response->successful()) {
                return $response->json('result');
            }
        } catch (\Exception $e) {
            Log::error('Error sending message with keyboard: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Send message with Reply Keyboard and then Inline buttons
     * Отправляет сначала Reply Keyboard, затем сообщение с Inline кнопками
     */
    private function sendMessageWithReplyAndInline(TelegramBot $bot, int $chatId, string $text, array $inlineKeyboard): ?array
    {
        try {
            // Сначала устанавливаем Reply Keyboard пустым сообщением (не видно пользователю)
            // Но это не нужно - Telegram сохраняет Reply Keyboard пока мы его явно не уберём
            
            // Отправляем сообщение с Inline кнопками
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$bot->token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => $inlineKeyboard,
                    ]),
                ]);

            if ($response->successful()) {
                return $response->json('result');
            }
        } catch (\Exception $e) {
            Log::error('Error sending message with reply and inline: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Установить постоянную клавиатуру (Reply Keyboard)
     */
    private function setReplyKeyboard(TelegramBot $bot, int $chatId): void
    {
        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$bot->token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => '⌨️',
                'reply_markup' => json_encode($this->getReplyKeyboard()),
            ]);
        } catch (\Exception $e) {
            Log::error('Error setting reply keyboard: ' . $e->getMessage());
        }
    }

    /**
     * Удалить сообщение
     */
    private function deleteMessage(TelegramBot $bot, int $chatId, int $messageId): bool
    {
        try {
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$bot->token}/deleteMessage", [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Error deleting message: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Edit message text
     */
    private function editMessageText(TelegramBot $bot, int $chatId, int $messageId, string $text, ?array $keyboard = null): ?array
    {
        try {
            $params = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ];

            if ($keyboard) {
                $params['reply_markup'] = json_encode($keyboard);
            }

            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$bot->token}/editMessageText", $params);

            if ($response->successful()) {
                return $response->json('result');
            }
        } catch (\Exception $e) {
            Log::error('Error editing message: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Edit or send message (send new if edit fails)
     */
    private function editOrSendMessage(TelegramBot $bot, int $chatId, ?int $messageId, string $text, ?array $keyboard = null): ?array
    {
        if ($messageId) {
            $result = $this->editMessageText($bot, $chatId, $messageId, $text, $keyboard);
            if ($result) {
                return $result;
            }
        }
        
        return $keyboard 
            ? $this->sendMessageWithKeyboard($bot, $chatId, $text, $keyboard)
            : $this->sendMessage($bot, $chatId, $text);
    }

    /**
     * Send photo
     */
    private function sendPhoto(TelegramBot $bot, int $chatId, string $filePath, ?string $caption = null, ?array $keyboard = null): ?array
    {
        try {
            $params = [
                'chat_id' => $chatId,
            ];

            if ($caption) {
                $params['caption'] = $caption;
                $params['parse_mode'] = 'HTML';
            }

            if ($keyboard) {
                $params['reply_markup'] = json_encode($keyboard);
            }

            $response = Http::timeout(30)
                ->attach('photo', file_get_contents($filePath), basename($filePath))
                ->post("https://api.telegram.org/bot{$bot->token}/sendPhoto", $params);

            if ($response->successful()) {
                return $response->json('result');
            } else {
                Log::error('Failed to send photo', ['status' => $response->status(), 'body' => $response->body()]);
            }
        } catch (\Exception $e) {
            Log::error('Error sending photo: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Send document
     */
    private function sendDocument(TelegramBot $bot, int $chatId, string $filePath, ?string $caption = null, ?array $keyboard = null): ?array
    {
        try {
            $params = [
                'chat_id' => $chatId,
            ];

            if ($caption) {
                $params['caption'] = $caption;
                $params['parse_mode'] = 'HTML';
            }

            if ($keyboard) {
                $params['reply_markup'] = json_encode($keyboard);
            }

            $response = Http::timeout(30)
                ->attach('document', file_get_contents($filePath), basename($filePath))
                ->post("https://api.telegram.org/bot{$bot->token}/sendDocument", $params);

            if ($response->successful()) {
                return $response->json('result');
            }
        } catch (\Exception $e) {
            Log::error('Error sending document: ' . $e->getMessage());
        }
        return null;
    }
}
