<?php

namespace LetoceilingCoder\Telegram\Types;

/**
 * Представляет участника чата
 * https://core.telegram.org/bots/api#chatmember
 */
class ChatMember
{
    public string $status; // "creator", "administrator", "member", "restricted", "left", "kicked"
    public User $user;
    public ?string $customTitle = null;
    public ?bool $isAnonymous = null;
    public ?array $canBeEdited = null;

    public static function fromArray(array $data): self
    {
        $member = new self();
        $member->status = $data['status'];
        $member->user = User::fromArray($data['user']);
        $member->customTitle = $data['custom_title'] ?? null;
        $member->isAnonymous = $data['is_anonymous'] ?? null;
        return $member;
    }

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'user' => $this->user->toArray(),
            'custom_title' => $this->customTitle,
            'is_anonymous' => $this->isAnonymous,
        ], fn($value) => $value !== null);
    }

    public function isCreator(): bool
    {
        return $this->status === 'creator';
    }

    public function isAdministrator(): bool
    {
        return $this->status === 'administrator';
    }

    public function isMember(): bool
    {
        return $this->status === 'member';
    }

    public function isRestricted(): bool
    {
        return $this->status === 'restricted';
    }

    public function hasLeft(): bool
    {
        return $this->status === 'left';
    }

    public function isKicked(): bool
    {
        return $this->status === 'kicked';
    }
}

