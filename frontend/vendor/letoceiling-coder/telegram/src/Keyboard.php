<?php

namespace LetoceilingCoder\Telegram;

/**
 * Вспомогательный класс для создания клавиатур Telegram
 */
class Keyboard
{
    protected array $keyboard = [];
    protected bool $isInline = false;

    // ==========================================
    // Factory methods
    // ==========================================

    /**
     * Создать inline клавиатуру
     */
    public static function inline(): self
    {
        $instance = new self();
        $instance->isInline = true;
        return $instance;
    }

    /**
     * Создать обычную клавиатуру
     */
    public static function reply(): self
    {
        return new self();
    }

    // ==========================================
    // Adding buttons
    // ==========================================

    /**
     * Добавить строку с кнопками
     */
    public function row(array $buttons): self
    {
        $this->keyboard[] = $buttons;
        return $this;
    }

    /**
     * Добавить кнопку в текущую строку
     */
    public function button(string $text, array $params = []): self
    {
        // Валидация
        Validator::validateButtonText($text);
        
        if (isset($params['callback_data'])) {
            Validator::validateCallbackData($params['callback_data']);
        }
        
        if (isset($params['url'])) {
            Validator::validateUrl($params['url']);
        }
        
        if (empty($this->keyboard)) {
            $this->keyboard[] = [];
        }

        $lastRow = count($this->keyboard) - 1;
        
        if ($this->isInline) {
            $button = ['text' => $text];
            $button = array_merge($button, $params);
        } else {
            $button = ['text' => $text];
            if (isset($params['request_contact'])) {
                $button['request_contact'] = $params['request_contact'];
            }
            if (isset($params['request_location'])) {
                $button['request_location'] = $params['request_location'];
            }
        }

        $this->keyboard[$lastRow][] = $button;
        return $this;
    }

    /**
     * Добавить кнопку с URL
     */
    public function url(string $text, string $url): self
    {
        if (!$this->isInline) {
            throw new \Exception('URL buttons only work with inline keyboards');
        }
        
        return $this->button($text, ['url' => $url]);
    }

    /**
     * Добавить кнопку с callback data
     */
    public function callback(string $text, string $callbackData): self
    {
        if (!$this->isInline) {
            throw new \Exception('Callback buttons only work with inline keyboards');
        }
        
        return $this->button($text, ['callback_data' => $callbackData]);
    }

    /**
     * Добавить кнопку WebApp
     */
    public function webApp(string $text, string $url): self
    {
        if (!$this->isInline) {
            throw new \Exception('WebApp buttons only work with inline keyboards');
        }
        
        return $this->button($text, ['web_app' => ['url' => $url]]);
    }

    /**
     * Добавить кнопку с inline query
     */
    public function switchInlineQuery(string $text, string $query = ''): self
    {
        if (!$this->isInline) {
            throw new \Exception('Switch inline query buttons only work with inline keyboards');
        }
        
        return $this->button($text, ['switch_inline_query' => $query]);
    }

    /**
     * Добавить кнопку с inline query в текущем чате
     */
    public function switchInlineQueryCurrentChat(string $text, string $query = ''): self
    {
        if (!$this->isInline) {
            throw new \Exception('Switch inline query buttons only work with inline keyboards');
        }
        
        return $this->button($text, ['switch_inline_query_current_chat' => $query]);
    }

    /**
     * Добавить кнопку запроса контакта
     */
    public function requestContact(string $text): self
    {
        if ($this->isInline) {
            throw new \Exception('Request contact buttons only work with reply keyboards');
        }
        
        return $this->button($text, ['request_contact' => true]);
    }

    /**
     * Добавить кнопку запроса локации
     */
    public function requestLocation(string $text): self
    {
        if ($this->isInline) {
            throw new \Exception('Request location buttons only work with reply keyboards');
        }
        
        return $this->button($text, ['request_location' => true]);
    }

    // ==========================================
    // Building
    // ==========================================

    /**
     * Получить массив клавиатуры
     */
    public function get(): array
    {
        if ($this->isInline) {
            return [
                'inline_keyboard' => $this->keyboard,
            ];
        }

        return [
            'keyboard' => $this->keyboard,
            'resize_keyboard' => true,
        ];
    }

    /**
     * Получить JSON клавиатуры
     */
    public function getJson(): string
    {
        return json_encode($this->get());
    }

    /**
     * Установить дополнительные параметры для reply клавиатуры
     */
    public function setOptions(array $options): self
    {
        if ($this->isInline) {
            throw new \Exception('Options only work with reply keyboards');
        }

        // Параметры будут добавлены при вызове get()
        return $this;
    }

    // ==========================================
    // Предустановленные клавиатуры
    // ==========================================

    /**
     * Удалить клавиатуру
     */
    public static function remove(bool $selective = false): array
    {
        return [
            'remove_keyboard' => true,
            'selective' => $selective,
        ];
    }

    /**
     * Показать "печатает..."
     */
    public static function forceReply(bool $selective = false): array
    {
        return [
            'force_reply' => true,
            'selective' => $selective,
        ];
    }

    /**
     * Быстрое создание inline клавиатуры из массива
     * 
     * Пример: Keyboard::makeInline([
     *     [['text' => 'Button 1', 'url' => 'https://example.com']],
     *     [['text' => 'Button 2', 'callback_data' => 'data']],
     * ])
     */
    public static function makeInline(array $buttons): array
    {
        return ['inline_keyboard' => $buttons];
    }

    /**
     * Быстрое создание reply клавиатуры из массива текстов
     * 
     * Пример: Keyboard::makeReply(['Button 1', 'Button 2'])
     */
    public static function makeReply(array $buttons, int $columns = 2): array
    {
        $keyboard = [];
        $row = [];
        
        foreach ($buttons as $button) {
            $row[] = ['text' => $button];
            
            if (count($row) >= $columns) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        
        if (!empty($row)) {
            $keyboard[] = $row;
        }

        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
        ];
    }
}

