<?php
namespace Czar\AiChatbot\Model\Sync;

use Magento\Widget\Model\ResourceModel\Widget\Instance\CollectionFactory;

class WidgetProvider
{
    public function __construct(private CollectionFactory $collectionFactory)
    {
    }

    public function getContentType(): string
    {
        return 'widget';
    }

    public function getTotalCount(int $storeId = 0): int
    {
        return $this->collectionFactory->create()->getSize();
    }

    public function getItems(int $page, int $pageSize, int $storeId = 0): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setCurPage($page);
        $collection->setPageSize($pageSize);

        $items = [];
        foreach ($collection as $widget) {
            $items[] = [
                'entity_id' => (string) $widget->getId(),
                'content_type' => 'widget',
                'title' => (string) ($widget->getTitle() ?: $widget->getInstanceType()),
                'content' => (string) $widget->getWidgetParameters(),
                'summary' => (string) $widget->getInstanceType(),
                'permalink' => '',
                'status' => 'active',
                'updated_at' => (string) ($widget->getData('updated_at') ?: ''),
                'metadata' => [
                    'instance_type' => (string) $widget->getInstanceType(),
                    'sort_order' => (string) $widget->getSortOrder(),
                    'store_id' => $storeId,
                ],
            ];
        }

        return $items;
    }
}
