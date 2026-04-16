<?php
namespace Czar\AiChatbot\Model\Sync;

class PolicyProvider
{
    public function __construct(private CmsPageProvider $cmsPageProvider)
    {
    }

    public function getContentType(): string
    {
        return 'policy';
    }

    public function getTotalCount(int $storeId = 0): int
    {
        return count($this->getItems(1, 200, $storeId));
    }

    public function getItems(int $page, int $pageSize, int $storeId = 0): array
    {
        $items = array_filter(
            $this->cmsPageProvider->getItems($page, $pageSize, $storeId),
            function (array $item): bool {
                $haystack = strtolower(($item['title'] ?? '') . ' ' . ($item['metadata']['identifier'] ?? ''));

                return str_contains($haystack, 'policy')
                    || str_contains($haystack, 'privacy')
                    || str_contains($haystack, 'return')
                    || str_contains($haystack, 'terms')
                    || str_contains($haystack, 'shipping');
            }
        );

        return array_map(function (array $item): array {
            $item['content_type'] = 'policy';

            return $item;
        }, array_values($items));
    }
}
