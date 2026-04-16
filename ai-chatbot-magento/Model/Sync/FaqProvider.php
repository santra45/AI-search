<?php
namespace Czar\AiChatbot\Model\Sync;

class FaqProvider
{
    public function __construct(
        private CmsPageProvider $cmsPageProvider,
        private CmsBlockProvider $cmsBlockProvider
    ) {
    }

    public function getContentType(): string
    {
        return 'faq';
    }

    public function getTotalCount(int $storeId = 0): int
    {
        return count($this->getItems(1, 200, $storeId));
    }

    public function getItems(int $page, int $pageSize, int $storeId = 0): array
    {
        $items = array_merge(
            $this->cmsPageProvider->getItems($page, $pageSize, $storeId),
            $this->cmsBlockProvider->getItems($page, $pageSize, $storeId)
        );

        $items = array_filter($items, function (array $item): bool {
            $haystack = strtolower(($item['title'] ?? '') . ' ' . ($item['summary'] ?? '') . ' ' . ($item['metadata']['identifier'] ?? ''));

            return str_contains($haystack, 'faq')
                || str_contains($haystack, 'question')
                || str_contains($haystack, 'help')
                || str_contains($haystack, 'support');
        });

        return array_map(function (array $item): array {
            $item['content_type'] = 'faq';

            return $item;
        }, array_values($items));
    }
}
