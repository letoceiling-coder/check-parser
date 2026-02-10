<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotSettings extends Model
{
    protected $fillable = [
        'telegram_bot_id',
        'current_raffle_id',
        'total_slots',
        'slot_price',
        'slots_mode',
        'is_active',
        'qr_image_path',
        'payment_description',
        'support_contact',
        'raffle_info',
        'prize_description',
        'msg_welcome',
        'msg_no_slots',
        'msg_ask_fio',
        'msg_ask_phone',
        'msg_ask_inn',
        'msg_confirm_data',
        'msg_show_qr',
        'msg_wait_check',
        'msg_check_received',
        'msg_check_approved',
        'msg_check_rejected',
        'msg_check_duplicate',
        'msg_admin_request_sent',
        'msg_admin_request_approved',
        'msg_admin_request_rejected',
        'msg_about_raffle',
        'msg_my_tickets',
        'msg_no_tickets',
        'msg_support',
        'receipt_parser_method',
    ];

    /** Метод парсинга суммы и даты из чеков: legacy, enhanced, enhanced_ai (улучшенный + AI fallback при низкой уверенности) */
    public const PARSER_LEGACY = 'legacy';
    public const PARSER_ENHANCED = 'enhanced';
    public const PARSER_ENHANCED_AI = 'enhanced_ai';

    protected $casts = [
        'total_slots' => 'integer',
        'slot_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // ==========================================
    // Дефолтные сообщения
    // ==========================================

    public const DEFAULTS = [
        // Старые сообщения (для обратной совместимости)
        'msg_welcome' => "🎉 Добро пожаловать в розыгрыш!\n\n💰 Стоимость участия: {price} ₽ = 1 номерок\n📊 Свободных мест: {available_slots} из {total_slots}\n\nНажмите «Участвовать» чтобы начать!",
        
        'msg_no_slots' => "😔 К сожалению, все места уже заняты.\n\nМы уведомим вас, когда места появятся!",
        
        'msg_ask_fio' => "📝 Напиши своё ФИО полностью (например: Иванов Иван Иванович):",
        
        'msg_ask_phone' => "📱 Напиши свой номер телефона для связи:",
        
        'msg_ask_inn' => "🔢 Введите ваш ИНН (12 цифр для физ.лица):",
        
        'msg_confirm_data' => "✅ Проверьте введённые данные:\n\n👤 ФИО: {fio}\n📱 Телефон: {phone}\n\nВсё верно?",
        
        'msg_show_qr' => "💳 Отсканируйте QR-код для оплаты\n\n💰 Стоимость: {price} ₽ = 1 номерок\n📝 Назначение: {payment_description}\n\nПосле оплаты отправьте чек в формате PDF.",
        
        'msg_wait_check' => "⏳ Отправьте чек об оплате в формате PDF:",
        
        'msg_check_received' => "📄 Чек получен и отправлен на проверку!\n\n⏳ Ожидайте подтверждения от администратора.",
        
        'msg_check_approved' => "✅ Платёж подтверждён!\n\n🎫 Ваши номерки: {tickets}\n\nУдачи в розыгрыше! 🍀",
        
        'msg_check_rejected' => "❌ Чек не принят.\n\n{reason}\n\nПроверьте оплату и отправьте чек повторно.",
        
        'msg_check_duplicate' => "⚠️ Этот чек уже был использован!\n\n{status_info}\n\nПожалуйста, отправьте другой чек для участия в розыгрыше.",
        
        'msg_admin_request_sent' => "📤 Запрос на роль администратора отправлен!\n\n⏳ Ожидайте рассмотрения.",
        
        'msg_admin_request_approved' => "✅ Поздравляем! Вам выдана роль администратора.\n\nТеперь вы будете получать уведомления о новых чеках.",
        
        'msg_admin_request_rejected' => "❌ Запрос на роль администратора отклонён.\n\n{reason}",
        
        'msg_about_raffle' => "ℹ️ О розыгрыше\n\n🎁 Приз: {prize}\n💰 Стоимость: {price} ₽ = 1 номерок\n📊 Всего мест: {total_slots}\n✅ Свободно: {available_slots}\n\n{raffle_info}",
        
        'msg_my_tickets' => "🎫 Ваши номерки:\n\n{tickets}\n\nВсего: {count} шт.",
        
        'msg_no_tickets' => "🎫 У вас пока нет номерков.\n\nНажмите «🏠 Главная» чтобы участвовать в розыгрыше!",
        
        'msg_support' => "💬 Поддержка\n\nПо всем вопросам обращайтесь: {support_contact}",
        
        // === НОВЫЕ СООБЩЕНИЯ ДЛЯ ORDERS v7.0 ===
        
        'msg_welcome_new' => "Привет! Рад, что ты решил поучаствовать в нашей движухе! 🤝\n\nДля начала давай познакомимся, чтобы я мог записать тебя в список участников.\n\nНажми кнопку ниже, чтобы начать регистрацию 👇",
        
        'msg_welcome_returning' => "Рад видеть тебя снова, {first_name}! 🤝\n\nХочешь увеличить шансы и докупить ещё наклеек?\n\nТвои текущие номера: {ticket_numbers}\n\nНажми кнопку, чтобы оформить новую заявку 👇",
        
        'msg_sold_out_with_tickets' => "⛔️ Места закончились!\n\nТы уже в игре, твои номера: {ticket_numbers}. Следи за розыгрышем!",
        
        'msg_sold_out_no_tickets' => "⛔️ К сожалению, все места уже заняты.\n\nЕсли кто-то не оплатит бронь, место освободится. Следи за новостями.",
        
        'msg_ask_quantity' => "Стоимость одной наклейки: {price} руб.\n\nВведите количество наклеек, которые хотите приобрести (цифрой):",
        
        'msg_confirm_order' => "✅ Заявка сформирована!\n\n📦 Количество: {quantity} шт.\n💰 К оплате: {amount} руб.\n\nПодтверждаете заказ?",
        
        'msg_order_reserved' => "✅ Заявка сформирована! Бронь на 30 минут.\n\n📦 Количество: {quantity} шт.\n💰 К оплате: {amount} руб.\n\n👇 Реквизиты для оплаты:",
        
        'msg_payment_instructions' => "⚠️ ВНИМАНИЕ! ОЧЕНЬ ВАЖНО:\n\n1️⃣ Оплачивайте сумму СТРОГО ОДНИМ ПЛАТЕЖОМ. Не разбивайте оплату на части!\n2️⃣ В назначении платежа укажите: «Оплата наклейки».\n3️⃣ Мы принимаем чек только в формате PDF (выгрузка из банка).\n\n📄 Пришли мне чек в формате PDF-ФАЙЛА в ответ на это сообщение!",
        
        'msg_order_approved' => "✅ Платёж подтверждён! 🎉\n\n🎫 Ваши номерки: {ticket_numbers}\n\nУдачи в розыгрыше! 🍀",
        
        'msg_order_rejected' => "❌ Чек не принят.\n\n{reason}\n\nПроверьте оплату и оформите заявку заново через /start",
        
        'msg_order_expired' => "⏰ Время брони истекло!\n\nВаш заказ отменён. Места освобождены.\n\nВы можете оформить новую заявку, нажав /start",
        
        'msg_insufficient_slots' => "⚠️ Вы хотите {requested}, но осталось всего {available}.\n\nВведите другое число:",
    ];

    // ==========================================
    // Связи
    // ==========================================

    public function telegramBot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class);
    }

    public function currentRaffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class, 'current_raffle_id');
    }

    // ==========================================
    // Методы для получения сообщений
    // ==========================================

    /**
     * Получить сообщение с подстановкой переменных
     */
    public function getMessage(string $key, array $variables = []): string
    {
        $message = $this->{'msg_' . $key} ?? self::DEFAULTS['msg_' . $key] ?? '';
        
        foreach ($variables as $var => $value) {
            $message = str_replace('{' . $var . '}', $value, $message);
        }
        
        return $message;
    }

    /**
     * Получить приветственное сообщение
     */
    public function getWelcomeMessage(): string
    {
        return $this->getMessage('welcome', [
            'price' => number_format($this->slot_price, 0, '', ' '),
            'available_slots' => $this->getAvailableSlotsCount(),
            'total_slots' => $this->total_slots,
        ]);
    }

    /**
     * Получить сообщение "нет мест"
     */
    public function getNoSlotsMessage(): string
    {
        return $this->getMessage('no_slots');
    }

    /**
     * Получить сообщение с QR-кодом
     */
    public function getShowQrMessage(): string
    {
        return $this->getMessage('show_qr', [
            'price' => number_format($this->slot_price, 0, '', ' '),
            'payment_description' => $this->payment_description,
        ]);
    }

    /**
     * Получить сообщение об одобрении чека
     */
    public function getCheckApprovedMessage(array $tickets): string
    {
        return $this->getMessage('check_approved', [
            'tickets' => implode(', ', $tickets),
        ]);
    }

    // ==========================================
    // Расчёты
    // ==========================================

    /**
     * Получить количество свободных мест.
     * Использует текущий розыгрыш (total_slots - tickets_issued), чтобы корректно показывать места
     * даже если номерки ещё не инициализированы в таблице tickets.
     */
    public function getAvailableSlotsCount(): int
    {
        $raffle = null;
        if ($this->current_raffle_id) {
            $raffle = Raffle::find($this->current_raffle_id);
        }
        if (!$raffle || $raffle->status !== Raffle::STATUS_ACTIVE) {
            $raffle = Raffle::getCurrentForBot($this->telegram_bot_id);
            if ($raffle) {
                $this->current_raffle_id = $raffle->id;
                $this->save();
            }
        }
        if ($raffle && $raffle->status === Raffle::STATUS_ACTIVE) {
            return max(0, $raffle->total_slots - (int) $raffle->tickets_issued);
        }
        return Ticket::where('telegram_bot_id', $this->telegram_bot_id)
            ->whereNull('bot_user_id')
            ->count();
    }

    /**
     * Получить количество занятых мест
     */
    public function getIssuedSlotsCount(): int
    {
        return Ticket::where('telegram_bot_id', $this->telegram_bot_id)
            ->whereNotNull('bot_user_id')
            ->count();
    }

    /**
     * Рассчитать количество номерков по сумме
     */
    public function calculateTicketsCount(float $amount): int
    {
        if ($this->slot_price <= 0) {
            return 0;
        }
        return (int) floor($amount / $this->slot_price);
    }

    /**
     * Проверить, есть ли свободные места
     */
    public function hasAvailableSlots(): bool
    {
        return $this->getAvailableSlotsCount() > 0;
    }

    /**
     * Проверить, хватает ли мест для выдачи
     */
    public function hasEnoughSlots(int $count): bool
    {
        return $this->getAvailableSlotsCount() >= $count;
    }

    /**
     * Получить URL изображения QR-кода
     */
    public function getQrImageUrl(): ?string
    {
        if (!$this->qr_image_path) {
            return null;
        }
        return url('storage/' . $this->qr_image_path);
    }

    /**
     * Получить путь к файлу QR-кода
     */
    public function getQrImageFullPath(): ?string
    {
        if (!$this->qr_image_path) {
            return null;
        }
        return storage_path('app/public/' . $this->qr_image_path);
    }

    // ==========================================
    // Статические методы
    // ==========================================

    /**
     * Получить или создать настройки для бота
     */
    public static function getOrCreate(int $telegramBotId): self
    {
        return self::firstOrCreate(
            ['telegram_bot_id' => $telegramBotId],
            [
                'total_slots' => 500,
                'slot_price' => 10000.00,
                'slots_mode' => 'sequential',
                'is_active' => true,
                'receipt_parser_method' => self::PARSER_LEGACY,
                'qr_image_path' => 'bot-assets/default-qr.jpg',
                'payment_description' => 'Оплата наклейки',
            ]
        );
    }
}
