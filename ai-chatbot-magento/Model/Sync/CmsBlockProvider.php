<?php
namespace Czar\AiChatbot\Model\Sync;

use Magento\Cms\Model\ResourceModel\Block\CollectionFactory;

class CmsBlockProvider
{
    public function __construct(private CollectionFactory $collectionFactory)
    {
    }

    public function getContentType(): string
    {
        return 'cms_block';
    }

    public function getTotalCount(int $storeId = 0): int
    {
        return $this->buildCollection($storeId)->getSize();
    }

    public function getItems(int $page, int $pageSize, int $storeId = 0): array
    {
        $collection = $this->buildCollection($storeId);
        $collection->setCurPage($page);
        $collection->setPageSize($pageSize);

        $items = [];
        foreach ($collection as $block) {
            $items[] = [
                'entity_id' => (string) $block->getId(),
                'content_type' => 'cms_block',
                'title' => (string) $block->getTitle(),
                'content' => (string) $block->getContent(),
                'summary' => substr(trim(strip_tags((string) $block->getContent())), 0, 280),
                'permalink' => (string) $block->getIdentifier(),
                'status' => $block->getIsActive() ? 'active' : 'disabled',
                'updated_at' => (string) $block->getUpdateTime(),
                'metadata' => [
                    'identifier' => (string) $block->getIdentifier(),
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
        $collection->addFieldToFilter('is_active', 1);
        $collection->setOrder('block_id', 'ASC');

        return $collection;
    }
}
