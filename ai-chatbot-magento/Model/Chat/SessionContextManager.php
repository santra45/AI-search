<?php
namespace Czar\AiChatbot\Model\Chat;

use Czar\AiChatbot\Helper\Config;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Session\SessionManagerInterface;

class SessionContextManager
{
    public function __construct(
        private SessionManagerInterface $sessionManager,
        private CustomerSession $customerSession,
        private Config $config
    ) {
    }

    public function getSessionId(): string
    {
        $this->sessionManager->start();

        return (string) $this->sessionManager->getSessionId();
    }

    public function getCustomerId(?int $storeId = null): ?string
    {
        if (!$this->config->shouldLinkCustomerSessions($storeId) || !$this->customerSession->isLoggedIn()) {
            return null;
        }

        return (string) $this->customerSession->getCustomerId();
    }

    public function getConversationId(int $storeId): ?string
    {
        return $this->sessionManager->getData($this->getConversationKey($storeId)) ?: null;
    }

    public function setConversationId(int $storeId, string $conversationId): void
    {
        $this->sessionManager->setData($this->getConversationKey($storeId), $conversationId);
    }

    public function clearConversationId(int $storeId): void
    {
        $this->sessionManager->unsetData($this->getConversationKey($storeId));
    }

    private function getConversationKey(int $storeId): string
    {
        return 'ai_chatbot_conversation_' . $storeId;
    }
}
