<?php

namespace LetoceilingCoder\Telegram;

use LetoceilingCoder\Telegram\TelegramClient;

/**
 * Класс для работы с каналами и группами Telegram
 * Документация: https://core.telegram.org/bots/api#available-methods
 */
class Channel extends TelegramClient
{
    // ==========================================
    // Getting information
    // ==========================================

    /**
     * Получить информацию о чате
     */
    public function getChat(int|string $chatId): array
    {
        return $this->request('getChat', [
            'chat_id' => $chatId,
        ]);
    }

    /**
     * Получить количество участников чата
     */
    public function getChatMemberCount(int|string $chatId): int
    {
        $result = $this->request('getChatMemberCount', [
            'chat_id' => $chatId,
        ]);
        return $result ?? 0;
    }

    /**
     * Получить информацию об участнике чата
     */
    public function getChatMember(int|string $chatId, int $userId): array
    {
        return $this->request('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Получить список администраторов чата
     */
    public function getChatAdministrators(int|string $chatId): array
    {
        return $this->request('getChatAdministrators', [
            'chat_id' => $chatId,
        ]);
    }

    // ==========================================
    // Managing chat
    // ==========================================

    /**
     * Установить название чата
     */
    public function setChatTitle(int|string $chatId, string $title): array
    {
        Validator::validateChatId($chatId);
        Validator::validateChatTitle($title);
        
        return $this->request('setChatTitle', [
            'chat_id' => $chatId,
            'title' => $title,
        ]);
    }

    /**
     * Установить описание чата
     */
    public function setChatDescription(int|string $chatId, string $description): array
    {
        Validator::validateChatId($chatId);
        Validator::validateChatDescription($description);
        
        return $this->request('setChatDescription', [
            'chat_id' => $chatId,
            'description' => $description,
        ]);
    }

    /**
     * Установить фото чата
     */
    public function setChatPhoto(int|string $chatId, string $photo): array
    {
        return $this->request('setChatPhoto', [
            'chat_id' => $chatId,
            'photo' => $photo,
        ]);
    }

    /**
     * Удалить фото чата
     */
    public function deleteChatPhoto(int|string $chatId): array
    {
        return $this->request('deleteChatPhoto', [
            'chat_id' => $chatId,
        ]);
    }

    /**
     * Закрепить сообщение
     */
    public function pinChatMessage(
        int|string $chatId,
        int $messageId,
        bool $disableNotification = false
    ): array {
        return $this->request('pinChatMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'disable_notification' => $disableNotification,
        ]);
    }

    /**
     * Открепить сообщение
     */
    public function unpinChatMessage(
        int|string $chatId,
        ?int $messageId = null
    ): array {
        $params = ['chat_id' => $chatId];
        
        if ($messageId) {
            $params['message_id'] = $messageId;
        }

        return $this->request('unpinChatMessage', $params);
    }

    /**
     * Открепить все сообщения
     */
    public function unpinAllChatMessages(int|string $chatId): array
    {
        return $this->request('unpinAllChatMessages', [
            'chat_id' => $chatId,
        ]);
    }

    // ==========================================
    // Managing members
    // ==========================================

    /**
     * Исключить участника из чата
     */
    public function banChatMember(
        int|string $chatId,
        int $userId,
        ?int $untilDate = null,
        bool $revokeMessages = false
    ): array {
        $params = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'revoke_messages' => $revokeMessages,
        ];

        if ($untilDate) {
            $params['until_date'] = $untilDate;
        }

        return $this->request('banChatMember', $params);
    }

    /**
     * Разбанить участника чата
     */
    public function unbanChatMember(
        int|string $chatId,
        int $userId,
        bool $onlyIfBanned = true
    ): array {
        return $this->request('unbanChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'only_if_banned' => $onlyIfBanned,
        ]);
    }

    /**
     * Ограничить права участника
     */
    public function restrictChatMember(
        int|string $chatId,
        int $userId,
        array $permissions,
        array $params = []
    ): array {
        return $this->request('restrictChatMember', array_merge([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'permissions' => json_encode($permissions),
        ], $params));
    }

    /**
     * Повысить участника до администратора
     */
    public function promoteChatMember(
        int|string $chatId,
        int $userId,
        array $params = []
    ): array {
        return $this->request('promoteChatMember', array_merge([
            'chat_id' => $chatId,
            'user_id' => $userId,
        ], $params));
    }

    /**
     * Установить кастомный титул администратора
     */
    public function setChatAdministratorCustomTitle(
        int|string $chatId,
        int $userId,
        string $customTitle
    ): array {
        Validator::validateChatId($chatId);
        Validator::validateUserId($userId);
        Validator::validateCustomTitle($customTitle);
        
        return $this->request('setChatAdministratorCustomTitle', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'custom_title' => $customTitle,
        ]);
    }

    /**
     * Забанить отправителя сообщений в канале
     */
    public function banChatSenderChat(
        int|string $chatId,
        int $senderChatId
    ): array {
        return $this->request('banChatSenderChat', [
            'chat_id' => $chatId,
            'sender_chat_id' => $senderChatId,
        ]);
    }

    /**
     * Разбанить отправителя сообщений в канале
     */
    public function unbanChatSenderChat(
        int|string $chatId,
        int $senderChatId
    ): array {
        return $this->request('unbanChatSenderChat', [
            'chat_id' => $chatId,
            'sender_chat_id' => $senderChatId,
        ]);
    }

    // ==========================================
    // Chat permissions
    // ==========================================

    /**
     * Установить разрешения по умолчанию для чата
     */
    public function setChatPermissions(
        int|string $chatId,
        array $permissions,
        bool $useIndependentChatPermissions = false
    ): array {
        return $this->request('setChatPermissions', [
            'chat_id' => $chatId,
            'permissions' => json_encode($permissions),
            'use_independent_chat_permissions' => $useIndependentChatPermissions,
        ]);
    }

    // ==========================================
    // Invite links
    // ==========================================

    /**
     * Экспортировать ссылку приглашения
     */
    public function exportChatInviteLink(int|string $chatId): string
    {
        $result = $this->request('exportChatInviteLink', [
            'chat_id' => $chatId,
        ]);
        return $result ?? '';
    }

    /**
     * Создать ссылку приглашения
     */
    public function createChatInviteLink(
        int|string $chatId,
        array $params = []
    ): array {
        return $this->request('createChatInviteLink', array_merge([
            'chat_id' => $chatId,
        ], $params));
    }

    /**
     * Редактировать ссылку приглашения
     */
    public function editChatInviteLink(
        int|string $chatId,
        string $inviteLink,
        array $params = []
    ): array {
        return $this->request('editChatInviteLink', array_merge([
            'chat_id' => $chatId,
            'invite_link' => $inviteLink,
        ], $params));
    }

    /**
     * Отозвать ссылку приглашения
     */
    public function revokeChatInviteLink(
        int|string $chatId,
        string $inviteLink
    ): array {
        return $this->request('revokeChatInviteLink', [
            'chat_id' => $chatId,
            'invite_link' => $inviteLink,
        ]);
    }

    /**
     * Одобрить запрос на вступление
     */
    public function approveChatJoinRequest(
        int|string $chatId,
        int $userId
    ): array {
        return $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Отклонить запрос на вступление
     */
    public function declineChatJoinRequest(
        int|string $chatId,
        int $userId
    ): array {
        return $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    // ==========================================
    // Helper methods
    // ==========================================

    /**
     * Проверить, является ли пользователь участником канала
     */
    public function isMember(int|string $chatId, int $userId): bool
    {
        try {
            $member = $this->getChatMember($chatId, $userId);
            $status = $member['status'] ?? 'left';
            return in_array($status, ['creator', 'administrator', 'member']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Проверить, является ли пользователь администратором
     */
    public function isAdmin(int|string $chatId, int $userId): bool
    {
        try {
            $member = $this->getChatMember($chatId, $userId);
            $status = $member['status'] ?? 'left';
            return in_array($status, ['creator', 'administrator']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Покинуть чат
     */
    public function leaveChat(int|string $chatId): array
    {
        return $this->request('leaveChat', [
            'chat_id' => $chatId,
        ]);
    }

    /**
     * Установить sticker set для группы
     */
    public function setChatStickerSet(
        int|string $chatId,
        string $stickerSetName
    ): array {
        return $this->request('setChatStickerSet', [
            'chat_id' => $chatId,
            'sticker_set_name' => $stickerSetName,
        ]);
    }

    /**
     * Удалить sticker set из группы
     */
    public function deleteChatStickerSet(int|string $chatId): array
    {
        return $this->request('deleteChatStickerSet', [
            'chat_id' => $chatId,
        ]);
    }

    /**
     * Получить menu button пользователя
     */
    public function getChatMenuButton(?int $chatId = null): array
    {
        $params = [];
        if ($chatId) {
            $params['chat_id'] = $chatId;
        }
        return $this->request('getChatMenuButton', $params);
    }

    /**
     * Установить menu button пользователя
     */
    public function setChatMenuButton(?int $chatId = null, ?array $menuButton = null): array
    {
        $params = [];
        if ($chatId) {
            $params['chat_id'] = $chatId;
        }
        if ($menuButton) {
            $params['menu_button'] = json_encode($menuButton);
        }
        return $this->request('setChatMenuButton', $params);
    }
}

