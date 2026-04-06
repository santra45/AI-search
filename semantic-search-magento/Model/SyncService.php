<?php
namespace Czar\SemanticSearch\Model;

use Czar\SemanticSearch\Helper\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

class SyncService
{
    public function __construct(
        private Config $config,
        private CollectionFactory $collectionFactory,
        private ProductFormatter $productFormatter,
        private ApiClient $apiClient,
        private ProductRepositoryInterface $productRepository
    ) {
    }

    public function syncAll(int $batchSize = 50): array
    {
        if (!$this->config->isEnabled()) {
            return ['status' => 'disabled', 'processed' => 0, 'failed' => 0];
        }

        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['name', 'price', 'description', 'short_description']);

        $total = (int) $collection->getSize();
        if ($total === 0) {
            return ['status' => 'empty', 'processed' => 0, 'failed' => 0];
        }

        $totalBatches = (int) ceil($total / $batchSize);
        $processed = 0;
        $failed = 0;

        for ($batch = 1; $batch <= $totalBatches; $batch++) {
            $batchCollection = $this->collectionFactory->create();
            $batchCollection->addAttributeToSelect(['name', 'price', 'description', 'short_description']);
            $batchCollection->setPageSize($batchSize)->setCurPage($batch);

            $products = [];
            foreach ($batchCollection as $product) {
                $products[] = $this->productFormatter->format($product);
            }

            $result = $this->apiClient->syncBatch($products, $batch, $totalBatches);
            $processed += (int) ($result['success_count'] ?? 0);
            $failed += (int) ($result['failed_count'] ?? count($products));
        }

        return [
            'status' => 'completed',
            'total' => $total,
            'processed' => $processed,
            'failed' => $failed,
            'total_batches' => $totalBatches,
        ];
    }

    public function syncSingleById(int $productId): array
    {
        if (!$this->config->isEnabled()) {
            return ['status' => 'disabled'];
        }

        $product = $this->productRepository->getById($productId);
        $formatted = $this->productFormatter->format($product);

        return $this->apiClient->syncBatch([$formatted], 1, 1);
    }

    public function deleteSingleById(int $productId): array
    {
        if (!$this->config->isEnabled()) {
            return ['status' => 'disabled'];
        }

        return $this->apiClient->deleteProduct((string) $productId);
    }
}
