<?php
namespace Czar\AiChatbot\Observer;

use Czar\AiChatbot\Model\Sync\SyncService;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class ReviewDeleteAfter implements ObserverInterface
{
    public function __construct(private SyncService $syncService, private LoggerInterface $logger)
    {
    }

    public function execute(Observer $observer): void
    {
        $review = $observer->getEvent()->getObject();
        if (!$review || !$review->getId()) {
            return;
        }

        try {
            $this->syncService->deleteEntity('review', (int) $review->getId(), 0);
        } catch (\Throwable $exception) {
            $this->logger->warning('AI Chatbot review delete sync failed.', ['message' => $exception->getMessage()]);
        }
    }
}
