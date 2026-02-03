<?php

namespace LetoceilingCoder\Telegram\Types;

/**
 * Представляет чат Telegram
 * https://core.telegram.org/bots/api#chat
 */
class Chat
{
    public int $id;
    public string $type; // "private", "group", "supergroup", "channel"
    public ?string $title = null;
    public ?string $username = null;
    public ?string $firstName = null;
    public ?string $lastName = null;
    public ?bool $isForum = null;

    public static function fromArray(array $data): self
    {
        $chat = new self();
        $chat->id = $data['id'];
        $chat->type = $data['type'];
        $chat->title = $data['title'] ?? null;
        $chat->username = $data['username'] ?? null;
        $chat->firstName = $data['first_name'] ?? null;
        $chat->lastName = $data['last_name'] ?? null;
        $chat->isForum = $data['is_forum'] ?? null;
        return $chat;
    }

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'username' => $this->username,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'is_forum' => $this->isForum,
        ], fn($value) => $value !== null);
    }

    public function isPrivate(): bool
    {
        return $this->type === 'private';
    }

    public function isGroup(): bool
    {
        return in_array($this->type, ['group', 'supergroup']);
    }

    public function isChannel(): bool
    {
        return $this->type === 'channel';
    }
}

