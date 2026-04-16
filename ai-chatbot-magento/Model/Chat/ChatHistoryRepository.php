<?php
namespace Czar\AiChatbot\Model\Chat;

use Czar\AiChatbot\Model\ApiClient;

class ChatHistoryRepository
{
    public function __construct(
        private ApiClient $apiClient,
        private ChatRequestBuilder $chatRequestBuilder,
        private SessionContextManager $sessionContextManager
    ) {
    }

    public function start(int $storeId): array
    {
        $response = $this->apiClient->startSession($this->chatRequestBuilder->buildStartPayload($storeId), $storeId);
        if (!empty($response['conversation_id'])) {
            $this->sessionContextManager->setConversationId($storeId, (string) $response['conversation_id']);
        }

        return $response;
    }

    public function sendMessage(string $message, int $storeId): array
    {
        $response = $this->apiClient->sendMessage($this->chatRequestBuilder->buildMessagePayload($message, $storeId), $storeId);
        if (!empty($response['conversation_id'])) {
            $this->sessionContextManager->setConversationId($storeId, (string) $response['conversation_id']);
        }

        return $response;
    }

    public function getHistory(int $storeId): array
    {
        $conversationId = $this->sessionContextManager->getConversationId($storeId);
        if ($conversationId) {
            return $this->apiClient->getHistory(['conversation_id' => $conversationId], $storeId);
        }

        return $this->apiClient->getHistory([
            'session_id' => $this->sessionContextManager->getSessionId(),
        ], $storeId);
    }

    public function reset(int $storeId): array
    {
        $response = $this->apiClient->resetSession($this->chatRequestBuilder->buildStartPayload($storeId), $storeId);
        $this->sessionContextManager->clearConversationId($storeId);

        return $response;
    }
}
