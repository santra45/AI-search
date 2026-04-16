<?php
namespace Czar\AiChatbot\Model\Sync;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class StoreConfigContentProvider
{
    private const CONFIG_PATHS = [
        'general/store_information/name',
        'general/store_information/phone',
        'trans_email/ident_general/email',
        'general/store_information/hours',
    ];

    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private StoreManagerInterface $storeManager
    ) {
    }

    public function getContentType(): string
    {
        return 'store_config';
    }

    public function getTotalCount(int $storeId = 0): int
    {
        return count($this->getItems(1, 100, $storeId));
    }

    public function getItems(int $page, int $pageSize, int $storeId = 0): array
    {
        $store = $this->storeManager->getStore($storeId);
        $items = [[
            'entity_id' => 'store-' . $storeId,
            'content_type' => 'store_config',
            'title' => 'Store information',
            'content' => 'Store name: ' . $store->getName() . "\nBase URL: " . $store->getBaseUrl(),
            'summary' => 'General storefront information and support contacts.',
            'permalink' => (string) $store->getBaseUrl(),
            'status' => 'active',
            'updated_at' => '',
            'metadata' => ['store_id' => $storeId],
        ]];

        foreach (self::CONFIG_PATHS as $index => $path) {
            $value = trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));
            if ($value === '') {
                continue;
            }

            $items[] = [
                'entity_id' => 'config-' . $storeId . '-' . $index,
                'content_type' => 'store_config',
                'title' => str_replace('/', ' ', $path),
                'content' => $value,
                'summary' => $value,
                'permalink' => (string) $store->getBaseUrl(),
                'status' => 'active',
                'updated_at' => '',
                'metadata' => [
                    'config_path' => $path,
                    'store_id' => $storeId,
                ],
            ];
        }

        return array_slice($items, max(0, ($page - 1) * $pageSize), $pageSize);
    }
}
