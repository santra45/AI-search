<?php
namespace Czar\AiChatbot\Model\Sync;

use Czar\AiChatbot\Model\ApiClient;

class ProductSyncInspector
{
    public function __construct(private ApiClient $apiClient)
    {
    }

    public function productsReady(?int $storeId = null): bool
    {
        $counts = $this->apiClient->getContentCounts($storeId);

        return !empty($counts['product_ready']) || (int) (($counts['counts']['product'] ?? 0)) > 0;
    }
}
