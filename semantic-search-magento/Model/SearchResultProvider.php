<?php
namespace Czar\SemanticSearch\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;

class SearchResultProvider
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private ProductFormatter $productFormatter
    ) {
    }

    public function getProductsByIds(array $productIds, ?int $storeId = null): array
    {
        $results = [];
        foreach ($productIds as $productId) {
            try {
                $product = $this->productRepository->getById((int) $productId, false, $storeId, true);
                $results[] = $this->productFormatter->format($product);
            } catch (\Throwable $exception) {
                continue;
            }
        }

        return $results;
    }
}
