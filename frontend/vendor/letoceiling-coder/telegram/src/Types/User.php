<?php

namespace LetoceilingCoder\Telegram\Types;

/**
 * Представляет пользователя Telegram
 * https://core.telegram.org/bots/api#user
 */
class User
{
    public int $id;
    public bool $isBot;
    public string $firstName;
    public ?string $lastName = null;
    public ?string $username = null;
    public ?string $languageCode = null;
    public ?bool $isPremium = null;
    public ?bool $addedToAttachmentMenu = null;
    public ?bool $canJoinGroups = null;
    public ?bool $canReadAllGroupMessages = null;
    public ?bool $supportsInlineQueries = null;

    public static function fromArray(array $data): self
    {
        $user = new self();
        $user->id = $data['id'];
        $user->isBot = $data['is_bot'];
        $user->firstName = $data['first_name'];
        $user->lastName = $data['last_name'] ?? null;
        $user->username = $data['username'] ?? null;
        $user->languageCode = $data['language_code'] ?? null;
        $user->isPremium = $data['is_premium'] ?? null;
        $user->addedToAttachmentMenu = $data['added_to_attachment_menu'] ?? null;
        $user->canJoinGroups = $data['can_join_groups'] ?? null;
        $user->canReadAllGroupMessages = $data['can_read_all_group_messages'] ?? null;
        $user->supportsInlineQueries = $data['supports_inline_queries'] ?? null;
        return $user;
    }

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'is_bot' => $this->isBot,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'username' => $this->username,
            'language_code' => $this->languageCode,
            'is_premium' => $this->isPremium,
            'added_to_attachment_menu' => $this->addedToAttachmentMenu,
            'can_join_groups' => $this->canJoinGroups,
            'can_read_all_group_messages' => $this->canReadAllGroupMessages,
            'supports_inline_queries' => $this->supportsInlineQueries,
        ], fn($value) => $value !== null);
    }
}

