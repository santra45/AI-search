<?php
namespace Czar\SemanticSearch\Observer;

use Czar\SemanticSearch\Model\SyncService;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class ProductDeleteAfter implements ObserverInterface
{
    public function __construct(
        private SyncService $syncService,
        private LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) {
            return;
        }

        try {
            $result = $this->syncService->deleteSingleById((int) $product->getId(), (int) $product->getStoreId());
            $this->logger->info('SemanticSearch product delete sync', ['product_id' => $product->getId(), 'result' => $result]);
        } catch (\Throwable $exception) {
            $this->logger->warning('SemanticSearch product delete sync failed', [
                'product_id' => $product->getId(),
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
