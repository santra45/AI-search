<?php
namespace Czar\AiChatbot\Model\Sync;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Store\Model\StoreManagerInterface;

class ProductProvider
{
    private const IGNORED_ATTRIBUTES = [
        'description',
        'short_description',
        'price',
        'special_price',
        'meta_keyword',
        'meta_title',
        'meta_description',
        'url_key',
        'url_path',
        'image',
        'small_image',
        'thumbnail',
        'media_gallery',
        'gallery',
        'category_ids',
        'required_options',
        'has_options',
        'status',
        'visibility',
    ];

    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private FilterBuilder $filterBuilder,
        private FilterGroupBuilder $filterGroupBuilder,
        private CategoryRepositoryInterface $categoryRepository,
        private StockRegistryInterface $stockRegistry,
        private EavConfig $eavConfig,
        private StoreManagerInterface $storeManager
    ) {
    }

    public function getContentType(): string
    {
        return 'product';
    }

    public function getTotalCount(int $storeId = 0): int
    {
        return $this->createSearchResult(1, 1)->getTotalCount();
    }

    public function getItems(int $page, int $pageSize, int $storeId = 0): array
    {
        $items = [];
        foreach ($this->createSearchResult($page, $pageSize)->getItems() as $productListItem) {
            $product = $this->productRepository->getById((int) $productListItem->getId(), false, $storeId, true);
            $items[] = $this->format($product, $storeId);
        }

        return $items;
    }

    private function format(Product $product, int $storeId): array
    {
        $store = $this->storeManager->getStore($storeId);
        $price = (float) $product->getFinalPrice();
        $regularPrice = (float) $product->getPrice();
        $salePrice = $price < $regularPrice ? $price : 0.0;

        return [
            'entity_id' => (string) $product->getId(),
            'content_type' => 'product',
            'title' => (string) $product->getName(),
            'content' => (string) ($product->getCustomAttribute('description')?->getValue() ?? ''),
            'summary' => (string) ($product->getCustomAttribute('short_description')?->getValue() ?? ''),
            'permalink' => (string) $product->getProductUrl(),
            'status' => $product->getStatus() == Status::STATUS_ENABLED ? 'active' : 'disabled',
            'updated_at' => (string) $product->getUpdatedAt(),
            'metadata' => [
                'sku' => (string) $product->getSku(),
                'store_id' => $storeId,
            ],
            'sku' => (string) $product->getSku(),
            'name' => (string) $product->getName(),
            'categories' => $this->getCategoryHierarchy($product),
            'tags' => $this->getTagText($product),
            'description' => (string) ($product->getCustomAttribute('description')?->getValue() ?? ''),
            'short_description' => (string) ($product->getCustomAttribute('short_description')?->getValue() ?? ''),
            'price' => $price,
            'regular_price' => $regularPrice,
            'sale_price' => $salePrice,
            'currency' => (string) $store->getCurrentCurrency()->getCode(),
            'currency_symbol' => (string) $store->getCurrentCurrency()->getCurrencySymbol(),
            'on_sale' => $salePrice > 0,
            'image_url' => (string) $product->getData('image'),
            'stock_status' => $this->getStockStatus($product),
            'average_rating' => $this->getAverageRating($product),
            'attributes' => $this->getAttributes($product),
        ];
    }

    private function createSearchResult(int $page, int $pageSize)
    {
        $builder = $this->searchCriteriaBuilderFactory->create();
        $builder->setCurrentPage($page);
        $builder->setPageSize($pageSize);
        $builder->setFilterGroups([
            $this->buildStatusFilterGroup(),
            $this->buildVisibilityFilterGroup(),
        ]);

        return $this->productRepository->getList($builder->create());
    }

    private function buildStatusFilterGroup(): FilterGroup
    {
        return $this->filterGroupBuilder
            ->setFilters([$this->buildFilter('status', Status::STATUS_ENABLED)])
            ->create();
    }

    private function buildVisibilityFilterGroup(): FilterGroup
    {
        return $this->filterGroupBuilder
            ->setFilters([
                $this->buildFilter(
                    'visibility',
                    [
                        Visibility::VISIBILITY_IN_CATALOG,
                        Visibility::VISIBILITY_IN_SEARCH,
                        Visibility::VISIBILITY_BOTH,
                    ],
                    'in'
                ),
            ])
            ->create();
    }

    private function buildFilter(string $field, mixed $value, string $condition = 'eq'): Filter
    {
        return $this->filterBuilder
            ->setField($field)
            ->setConditionType($condition)
            ->setValue($value)
            ->create();
    }

    private function getCategoryHierarchy(Product $product): string
    {
        $paths = [];
        foreach ((array) $product->getCategoryIds() as $categoryId) {
            try {
                $category = $this->categoryRepository->get((int) $categoryId);
                $pathNames = [];
                foreach (explode('/', (string) $category->getPath()) as $pathId) {
                    if ((int) $pathId <= 2) {
                        continue;
                    }
                    $pathNames[] = $this->categoryRepository->get((int) $pathId)->getName();
                }
                if ($pathNames !== []) {
                    $paths[] = implode(' > ', $pathNames);
                }
            } catch (\Throwable $exception) {
                continue;
            }
        }

        return implode(', ', array_unique($paths));
    }

    private function getTagText(Product $product): string
    {
        foreach (['tags', 'product_tags', 'search_tags'] as $attributeCode) {
            $value = $product->getAttributeText($attributeCode) ?: $product->getData($attributeCode);
            if (!$value) {
                continue;
            }

            $tags = is_array($value) ? $value : explode(',', (string) $value);
            return implode(' ', array_unique(array_filter(array_map('trim', $tags))));
        }

        return '';
    }

    private function getStockStatus(Product $product): string
    {
        try {
            $stock = $this->stockRegistry->getStockItem((int) $product->getId());

            return $stock->getIsInStock() ? 'instock' : 'outofstock';
        } catch (\Throwable $exception) {
            return 'outofstock';
        }
    }

    private function getAverageRating(Product $product): float
    {
        try {
            $summary = $product->getRatingSummary();

            return $summary ? round((float) $summary->getRatingSummary() / 20, 1) : 0.0;
        } catch (\Throwable $exception) {
            return 0.0;
        }
    }

    private function getAttributes(Product $product): array
    {
        $attributes = [];
        foreach ($product->getAttributes() as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            if (in_array($code, self::IGNORED_ATTRIBUTES, true)) {
                continue;
            }

            $storeLabel = trim((string) ($attribute->getStoreLabel() ?: $attribute->getFrontendLabel() ?: $code));
            $options = $this->resolveAttributeOptions($product, $code);
            if ($storeLabel === '' || $options === []) {
                continue;
            }

            $attributes[] = [
                'name' => $storeLabel,
                'options' => $options,
            ];
        }

        return $attributes;
    }

    private function resolveAttributeOptions(Product $product, string $attributeCode): array
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $attributeCode);
        if (!$attribute || !$attribute->getId()) {
            return [];
        }

        $value = $product->getData($attributeCode);
        if ($value === null || $value === '' || $value === false) {
            return [];
        }

        $frontendValue = $product->getAttributeText($attributeCode);
        if (is_array($frontendValue)) {
            return array_values(array_filter(array_map('trim', $frontendValue)));
        }

        if (is_string($frontendValue) && trim($frontendValue) !== '' && trim($frontendValue) !== 'No') {
            return array_values(array_filter(array_map('trim', explode(',', $frontendValue))));
        }

        return is_scalar($value)
            ? array_values(array_filter(array_map('trim', explode(',', (string) $value))))
            : [];
    }
}
