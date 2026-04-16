<?php
namespace Czar\AiChatbot\Model\Sync;

use Czar\AiChatbot\Helper\Config;

class ContentBatchAssembler
{
    private array $providersByType;
    private Config $config;

    public function __construct(
        Config $config,
        ProductProvider $productProvider,
        CmsPageProvider $cmsPageProvider,
        CmsBlockProvider $cmsBlockProvider,
        WidgetProvider $widgetProvider,
        ReviewProvider $reviewProvider,
        PolicyProvider $policyProvider,
        FaqProvider $faqProvider,
        StoreConfigContentProvider $storeConfigContentProvider
    ) {
        $this->config = $config;
        $this->providersByType = [
            'product' => $productProvider,
            'cms_page' => $cmsPageProvider,
            'cms_block' => $cmsBlockProvider,
            'widget' => $widgetProvider,
            'review' => $reviewProvider,
            'policy' => $policyProvider,
            'faq' => $faqProvider,
            'store_config' => $storeConfigContentProvider,
        ];
    }

    public function getManifest(int $storeId = 0): array
    {
        $manifest = [];
        foreach ($this->providersByType as $contentType => $provider) {
            if (!$this->config->shouldSyncContentType($contentType, $storeId)) {
                continue;
            }

            $manifest[$contentType] = (int) $provider->getTotalCount($storeId);
        }

        return $manifest;
    }

    public function getEnabledContentTypes(int $storeId = 0): array
    {
        return array_keys($this->getManifest($storeId));
    }

    public function getBatch(string $contentType, int $page, int $pageSize, int $storeId = 0): array
    {
        return $this->providersByType[$contentType]->getItems($page, $pageSize, $storeId) ?? [];
    }

    public function getSingleItem(string $contentType, int $entityId, int $storeId = 0): ?array
    {
        $provider = $this->providersByType[$contentType] ?? null;
        if ($provider === null) {
            return null;
        }

        $items = $provider->getItems(1, max(1, (int) $provider->getTotalCount($storeId)), $storeId);
        foreach ($items as $item) {
            if ((int) ($item['entity_id'] ?? 0) === $entityId) {
                return $item;
            }
        }

        return null;
    }
}
