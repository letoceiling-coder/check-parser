<?php

namespace LetoceilingCoder\Telegram;

/**
 * Константы лимитов и ограничений Telegram Bot API
 * Документация: https://core.telegram.org/bots/api
 * Лимиты: https://core.telegram.org/bots/faq#my-bot-is-hitting-limits-how-do-i-avoid-this
 */
class Limits
{
    // ==========================================
    // Текстовые сообщения
    // ==========================================
    
    /** Максимальная длина текста сообщения */
    public const MESSAGE_TEXT_MAX_LENGTH = 4096;
    
    /** Максимальная длина подписи к медиа */
    public const CAPTION_MAX_LENGTH = 1024;
    
    /** Максимальная длина callback_data */
    public const CALLBACK_DATA_MAX_LENGTH = 64;
    
    /** Максимальная длина inline query */
    public const INLINE_QUERY_MAX_LENGTH = 256;
    
    // ==========================================
    // Кнопки и клавиатуры
    // ==========================================
    
    /** Максимальное количество кнопок в одной строке */
    public const KEYBOARD_BUTTONS_PER_ROW_MAX = 8;
    
    /** Максимальное количество строк в клавиатуре */
    public const KEYBOARD_ROWS_MAX = 100;
    
    /** Максимальная длина текста кнопки */
    public const BUTTON_TEXT_MAX_LENGTH = 64;
    
    /** Максимальная длина URL кнопки */
    public const BUTTON_URL_MAX_LENGTH = 2048;
    
    // ==========================================
    // Имена и идентификаторы
    // ==========================================
    
    /** Максимальная длина имени пользователя */
    public const USER_FIRST_NAME_MAX_LENGTH = 64;
    
    /** Максимальная длина фамилии пользователя */
    public const USER_LAST_NAME_MAX_LENGTH = 64;
    
    /** Максимальная длина названия чата */
    public const CHAT_TITLE_MAX_LENGTH = 255;
    
    /** Максимальная длина описания чата */
    public const CHAT_DESCRIPTION_MAX_LENGTH = 255;
    
    /** Максимальная длина кастомного титула администратора */
    public const ADMIN_CUSTOM_TITLE_MAX_LENGTH = 16;
    
    // ==========================================
    // Опросы
    // ==========================================
    
    /** Максимальная длина вопроса опроса */
    public const POLL_QUESTION_MAX_LENGTH = 300;
    
    /** Максимальная длина варианта ответа в опросе */
    public const POLL_OPTION_MAX_LENGTH = 100;
    
    /** Максимальное количество вариантов ответа */
    public const POLL_OPTIONS_MAX_COUNT = 10;
    
    /** Минимальное количество вариантов ответа */
    public const POLL_OPTIONS_MIN_COUNT = 2;
    
    /** Максимальная длина объяснения в quiz */
    public const POLL_EXPLANATION_MAX_LENGTH = 200;
    
    // ==========================================
    // Медиа файлы
    // ==========================================
    
    /** Максимальный размер фото для загрузки (10 MB) */
    public const PHOTO_MAX_SIZE = 10 * 1024 * 1024;
    
    /** Максимальный размер документа для загрузки (50 MB) */
    public const DOCUMENT_MAX_SIZE = 50 * 1024 * 1024;
    
    /** Максимальный размер аудио для загрузки (50 MB) */
    public const AUDIO_MAX_SIZE = 50 * 1024 * 1024;
    
    /** Максимальный размер видео для загрузки (50 MB) */
    public const VIDEO_MAX_SIZE = 50 * 1024 * 1024;
    
    /** Максимальный размер голосового сообщения (1 MB) */
    public const VOICE_MAX_SIZE = 1 * 1024 * 1024;
    
    /** Максимальный размер видео заметки (1 MB) */
    public const VIDEO_NOTE_MAX_SIZE = 1 * 1024 * 1024;
    
    /** Максимальное количество файлов в media group */
    public const MEDIA_GROUP_MAX_COUNT = 10;
    
    /** Минимальное количество файлов в media group */
    public const MEDIA_GROUP_MIN_COUNT = 2;
    
    // ==========================================
    // Rate Limits (лимиты на частоту запросов)
    // ==========================================
    
    /** Максимум сообщений в секунду для одного чата */
    public const MESSAGES_PER_SECOND_PER_CHAT = 1;
    
    /** Максимум сообщений в минуту для группы */
    public const MESSAGES_PER_MINUTE_PER_GROUP = 20;
    
    /** Максимум сообщений в секунду для всех чатов */
    public const MESSAGES_PER_SECOND_TOTAL = 30;
    
    /** Максимум запросов в секунду к Bot API */
    public const API_REQUESTS_PER_SECOND = 30;
    
    // ==========================================
    // Inline mode
    // ==========================================
    
    /** Максимальное количество результатов inline запроса */
    public const INLINE_RESULTS_MAX_COUNT = 50;
    
    /** Максимальная длина switch_inline_query */
    public const INLINE_SWITCH_PM_TEXT_MAX_LENGTH = 64;
    
    // ==========================================
    // Stickers
    // ==========================================
    
    /** Максимальный размер файла стикера (512 KB) */
    public const STICKER_MAX_SIZE = 512 * 1024;
    
    /** Максимальная длина emoji для стикера */
    public const STICKER_EMOJI_MAX_LENGTH = 20;
    
    // ==========================================
    // Payments
    // ==========================================
    
    /** Максимальная длина названия товара */
    public const INVOICE_TITLE_MAX_LENGTH = 32;
    
    /** Максимальная длина описания товара */
    public const INVOICE_DESCRIPTION_MAX_LENGTH = 255;
    
    /** Максимальная длина payload */
    public const INVOICE_PAYLOAD_MAX_LENGTH = 128;
    
    /** Максимальное количество цен в инвойсе */
    public const INVOICE_PRICES_MAX_COUNT = 100;
    
    // ==========================================
    // Прочее
    // ==========================================
    
    /** Timeout для long polling (секунды) */
    public const LONG_POLLING_TIMEOUT_MAX = 50;
    
    /** Максимальное количество обновлений за один запрос */
    public const UPDATES_LIMIT_MAX = 100;
    
    /** Минимальный интервал между запросами getUpdates (мс) */
    public const GET_UPDATES_MIN_INTERVAL = 1000;
    
    /** Максимальная длина deep link параметра */
    public const DEEP_LINK_PARAM_MAX_LENGTH = 64;
    
    /** Минимальная длина deep link параметра */
    public const DEEP_LINK_PARAM_MIN_LENGTH = 1;
    
    // ==========================================
    // Mini App (WebApp)
    // ==========================================
    
    /** Максимальный возраст initData (24 часа в секундах) */
    public const INIT_DATA_MAX_AGE = 86400;
    
    /** Максимальная длина данных answerWebAppQuery */
    public const WEB_APP_QUERY_ANSWER_MAX_LENGTH = 4096;
}

