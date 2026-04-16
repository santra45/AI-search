<?php
namespace Czar\AiChatbot\Observer;

use Czar\AiChatbot\Model\Sync\SyncService;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class CmsPageDeleteAfter implements ObserverInterface
{
    public function __construct(private SyncService $syncService, private LoggerInterface $logger)
    {
    }

    public function execute(Observer $observer): void
    {
        $page = $observer->getEvent()->getObject();
        if (!$page || !$page->getId()) {
            return;
        }

        try {
            $this->syncService->deleteEntity('cms_page', (int) $page->getId(), 0);
        } catch (\Throwable $exception) {
            $this->logger->warning('AI Chatbot CMS page delete sync failed.', ['message' => $exception->getMessage()]);
        }
    }
}
