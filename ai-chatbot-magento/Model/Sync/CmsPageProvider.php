<?php
namespace Czar\AiChatbot\Model\Sync;

use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;

class CmsPageProvider
{
    public function __construct(private CollectionFactory $collectionFactory)
    {
    }

    public function getContentType(): string
    {
        return 'cms_page';
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
        foreach ($collection as $pageModel) {
            $items[] = [
                'entity_id' => (string) $pageModel->getId(),
                'content_type' => 'cms_page',
                'title' => (string) $pageModel->getTitle(),
                'content' => (string) $pageModel->getContent(),
                'summary' => (string) (strip_tags((string) $pageModel->getContent()) ?: ''),
                'permalink' => (string) $pageModel->getIdentifier(),
                'status' => $pageModel->getIsActive() ? 'active' : 'disabled',
                'updated_at' => (string) $pageModel->getUpdateTime(),
                'metadata' => [
                    'identifier' => (string) $pageModel->getIdentifier(),
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
        $collection->setOrder('page_id', 'ASC');

        return $collection;
    }
}
