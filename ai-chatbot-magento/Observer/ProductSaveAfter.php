<?php
namespace Czar\AiChatbot\Observer;

use Czar\AiChatbot\Model\Sync\SyncService;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class ProductSaveAfter implements ObserverInterface
{
    public function __construct(private SyncService $syncService, private LoggerInterface $logger)
    {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) {
            return;
        }

        try {
            $this->syncService->syncEntity('product', (int) $product->getId(), (int) $product->getStoreId());
        } catch (\Throwable $exception) {
            $this->logger->warning('AI Chatbot product sync failed.', ['message' => $exception->getMessage()]);
        }
    }
}
