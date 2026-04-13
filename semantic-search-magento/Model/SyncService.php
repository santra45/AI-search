<?php
namespace Czar\SemanticSearch\Model;

use Czar\SemanticSearch\Helper\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class SyncService
{
    public function __construct(
        private Config $config,
        private ProductCatalogProvider $productCatalogProvider,
        private ProductFormatter $productFormatter,
        private ApiClient $apiClient,
        private ProductRepositoryInterface $productRepository,
        private SyncStateManager $syncStateManager,
        private LoggerInterface $logger
    ) {
    }

    public function startSync(?int $storeId = null): array
    {
        $resolvedStoreId = $storeId ?? 0;
        if (!$this->config->isSearchReady($resolvedStoreId)) {
            throw new LocalizedException(__('Semantic Search is not configured.'));
        }

        $batchSize = $this->config->getBatchSize($resolvedStoreId);
        $total = $this->productCatalogProvider->getTotalCount($resolvedStoreId);
        if ($total === 0) {
            $this->syncStateManager->reset();

            return $this->syncStateManager->updateProgress(['status' => 'empty']);
        }

        $totalBatches = (int) ceil($total / $batchSize);

        return $this->syncStateManager->start($total, $totalBatches, $batchSize, $resolvedStoreId);
    }

    public function processNextBatch(): array
    {
        $state = $this->syncStateManager->getState();
        if ($state['status'] !== 'running') {
            return $this->syncStateManager->getProgressSummary();
        }

        $nextBatch = (int) $state['current_batch'] + 1;
        if ($nextBatch > (int) $state['total_batches']) {
            return $this->syncStateManager->markComplete();
        }

        try {
            $products = $this->productCatalogProvider->getProductsForPage(
                $nextBatch,
                (int) $state['batch_size'],
                (int) $state['store_id']
            );

            if ($products === []) {
                return $this->syncStateManager->markComplete();
            }

            $formattedProducts = [];
            foreach ($products as $product) {
                $formattedProducts[] = $this->productFormatter->format($product);
            }

            $result = $this->apiClient->syncBatch(
                $formattedProducts,
                $nextBatch,
                (int) $state['total_batches'],
                (int) $state['store_id']
            );

            $this->syncStateManager->updateProgress([
                'current_batch' => $nextBatch,
                'processed' => (int) $state['processed'] + (int) ($result['success_count'] ?? 0),
                'failed' => (int) $state['failed'] + (int) ($result['failed_count'] ?? 0),
            ]);

            if ($nextBatch >= (int) $state['total_batches']) {
                $this->syncStateManager->markComplete();
            }

            return $this->syncStateManager->getProgressSummary();
        } catch (\Throwable $exception) {
            $this->logger->error('Semantic Search batch sync failed', ['message' => $exception->getMessage()]);

            return $this->syncStateManager->markError($exception->getMessage());
        }
    }

    public function syncAll(?int $storeId = null): array
    {
        $state = $this->startSync($storeId);
        while ($state['status'] === 'running') {
            $state = $this->processNextBatch();
        }

        return $state;
    }

    public function syncSingleById(int $productId, ?int $storeId = null): array
    {
        if (!$this->config->isSearchReady($storeId)) {
            return ['status' => 'disabled'];
        }

        $product = $this->productRepository->getById($productId, false, $storeId, true);
        $formatted = $this->productFormatter->format($product);

        return $this->apiClient->syncBatch([$formatted], 1, 1, $storeId);
    }

    public function deleteSingleById(int $productId, ?int $storeId = null): array
    {
        if (!$this->config->isSearchReady($storeId)) {
            return ['status' => 'disabled'];
        }

        return $this->apiClient->deleteProduct((string) $productId, $storeId);
    }

    public function getProgress(): array
    {
        return $this->syncStateManager->getProgressSummary();
    }

    public function cancelSync(): array
    {
        return $this->syncStateManager->markCancelled();
    }

    public function reset(): array
    {
        return $this->syncStateManager->reset();
    }
}
