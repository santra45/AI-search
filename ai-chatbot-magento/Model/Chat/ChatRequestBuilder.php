<?php
namespace Czar\AiChatbot\Model\Chat;

class ChatRequestBuilder
{
    public function __construct(private SessionContextManager $sessionContextManager)
    {
    }

    public function buildStartPayload(int $storeId): array
    {
        return [
            'session_id' => $this->sessionContextManager->getSessionId(),
            'store_id' => (string) $storeId,
            'customer_id' => $this->sessionContextManager->getCustomerId($storeId),
            'conversation_id' => $this->sessionContextManager->getConversationId($storeId),
        ];
    }

    public function buildMessagePayload(string $message, int $storeId): array
    {
        return [
            'message' => trim($message),
            'session_id' => $this->sessionContextManager->getSessionId(),
            'store_id' => (string) $storeId,
            'customer_id' => $this->sessionContextManager->getCustomerId($storeId),
            'conversation_id' => $this->sessionContextManager->getConversationId($storeId),
        ];
    }
}
