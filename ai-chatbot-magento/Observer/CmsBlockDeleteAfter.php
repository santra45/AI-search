<?php
namespace Czar\AiChatbot\Observer;

use Czar\AiChatbot\Model\Sync\SyncService;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class CmsBlockDeleteAfter implements ObserverInterface
{
    public function __construct(private SyncService $syncService, private LoggerInterface $logger)
    {
    }

    public function execute(Observer $observer): void
    {
        $block = $observer->getEvent()->getObject();
        if (!$block || !$block->getId()) {
            return;
        }

        try {
            $this->syncService->deleteEntity('cms_block', (int) $block->getId(), 0);
        } catch (\Throwable $exception) {
            $this->logger->warning('AI Chatbot CMS block delete sync failed.', ['message' => $exception->getMessage()]);
        }
    }
}
