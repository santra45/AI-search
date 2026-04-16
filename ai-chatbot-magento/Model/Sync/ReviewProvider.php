<?php
namespace Czar\AiChatbot\Model\Sync;

use Czar\AiChatbot\Helper\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Review\Model\Review;
use Magento\Review\Model\ResourceModel\Review\CollectionFactory;

class ReviewProvider
{
    public function __construct(
        private CollectionFactory $collectionFactory,
        private ProductRepositoryInterface $productRepository,
        private Config $config
    ) {
    }

    public function getContentType(): string
    {
        return 'review';
    }

    public function getTotalCount(int $storeId = 0): int
    {
        return $this->buildCollection($storeId)->getSize();
    }

    public function getItems(int $page, int $pageSize, int $storeId = 0): array
    {
        $collection = $this->buildCollection($storeId);
        $collection->setCurPage($page);
        $collection->setPageSize(min($pageSize, $this->config->getReviewLimit($storeId)));

        $items = [];
        foreach ($collection as $review) {
            try {
                $product = $this->productRepository->getById((int) $review->getEntityPkValue(), false, $storeId, true);
            } catch (\Throwable $exception) {
                continue;
            }

            $detail = trim((string) ($review->getDetail() ?: $review->getTitle() ?: ''));
            if ($detail === '') {
                continue;
            }

            $items[] = [
                'entity_id' => (string) $review->getId(),
                'content_type' => 'review',
                'title' => (string) ($review->getTitle() ?: ('Review for ' . $product->getName())),
                'content' => $detail,
                'summary' => substr($detail, 0, 280),
                'permalink' => (string) $product->getProductUrl(),
                'status' => 'active',
                'updated_at' => (string) ($review->getCreatedAt() ?: ''),
                'metadata' => [
                    'product_id' => (string) $product->getId(),
                    'product_name' => (string) $product->getName(),
                    'nickname' => (string) $review->getNickname(),
                    'store_id' => $storeId,
                ],
            ];
        }

        return $items;
    }

    private function buildCollection(int $storeId)
    {
        $collection = $this->collectionFactory->create();
        $collection->addStoreFilter($storeId);
        $collection->addStatusFilter(Review::STATUS_APPROVED);
        $collection->setDateOrder();

        return $collection;
    }
}
