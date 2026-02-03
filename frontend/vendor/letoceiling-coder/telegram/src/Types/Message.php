<?php

namespace LetoceilingCoder\Telegram\Types;

/**
 * Представляет сообщение Telegram
 * https://core.telegram.org/bots/api#message
 */
class Message
{
    public int $messageId;
    public ?int $messageThreadId = null;
    public ?User $from = null;
    public ?Chat $senderChat = null;
    public int $date;
    public Chat $chat;
    public ?string $text = null;
    public ?array $entities = null;
    public ?string $caption = null;
    
    public static function fromArray(array $data): self
    {
        $message = new self();
        $message->messageId = $data['message_id'];
        $message->messageThreadId = $data['message_thread_id'] ?? null;
        $message->from = isset($data['from']) ? User::fromArray($data['from']) : null;
        $message->senderChat = isset($data['sender_chat']) ? Chat::fromArray($data['sender_chat']) : null;
        $message->date = $data['date'];
        $message->chat = Chat::fromArray($data['chat']);
        $message->text = $data['text'] ?? null;
        $message->entities = $data['entities'] ?? null;
        $message->caption = $data['caption'] ?? null;
        return $message;
    }

    public function toArray(): array
    {
        return array_filter([
            'message_id' => $this->messageId,
            'message_thread_id' => $this->messageThreadId,
            'from' => $this->from?->toArray(),
            'sender_chat' => $this->senderChat?->toArray(),
            'date' => $this->date,
            'chat' => $this->chat->toArray(),
            'text' => $this->text,
            'entities' => $this->entities,
            'caption' => $this->caption,
        ], fn($value) => $value !== null);
    }
}

