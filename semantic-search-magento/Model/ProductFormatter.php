<?php
namespace Czar\SemanticSearch\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\StoreManagerInterface;

class ProductFormatter
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
        'tax_class_id',
        'status',
        'visibility',
    ];

    public function __construct(
        private StoreManagerInterface $storeManager,
        private Image $imageHelper,
        private CategoryRepositoryInterface $categoryRepository,
        private StockRegistryInterface $stockRegistry,
        private EavConfig $eavConfig
    ) {
    }

    public function format(ProductInterface $product): array
    {
        $storeId = method_exists($product, 'getStoreId') ? (int) $product->getStoreId() : null;
        $store = $this->storeManager->getStore($storeId);
        $currency = $store->getCurrentCurrency();
        $priceInfo = $this->getPriceInfo($product);

        return [
            'sku' => $this->getProductSku($product),
            'product_id' => (string) $product->getId(),
            'name' => (string) $product->getName(),
            'categories' => $this->getCategoryHierarchy($product),
            'tags' => $this->getProductTags($product),
            'gender' => $this->extractGender($product),
            'description' => (string) ($product->getCustomAttribute('description')?->getValue() ?? ''),
            'brand' => $this->getProductBrand($product),
            'short_description' => (string) ($product->getCustomAttribute('short_description')?->getValue() ?? ''),
            'price' => $priceInfo['price'],
            'currency' => $currency->getCode(),
            'currency_symbol' => $currency->getCurrencySymbol(),
            'regular_price' => $priceInfo['regular_price'],
            'sale_price' => $priceInfo['sale_price'],
            'on_sale' => $priceInfo['on_sale'],
            'permalink' => $this->getProductUrl($product),
            'image_url' => $this->getProductImageUrl($product),
            'stock_status' => $this->getStockStatus($product),
            'average_rating' => $this->getAverageRating($product),
            'attributes' => $this->getAttributes($product),
        ];
    }

    private function getCategoryHierarchy(ProductInterface $product): string
    {
        $categoryIds = method_exists($product, 'getCategoryIds') ? $product->getCategoryIds() : [];
        $paths = [];

        foreach ($categoryIds as $categoryId) {
            try {
                $category = $this->categoryRepository->get($categoryId);
                $pathIds = explode('/', (string) $category->getPath());
                $names = [];

                foreach ($pathIds as $id) {
                    if ((int) $id <= 2) {
                        continue;
                    }

                    $pathCategory = $this->categoryRepository->get((int) $id);
                    $names[] = $pathCategory->getName();
                }

                if ($names !== []) {
                    $paths[] = implode(' > ', $names);
                }
            } catch (\Throwable $exception) {
                continue;
            }
        }

        return implode(', ', array_unique($paths));
    }

    private function getProductImageUrl(ProductInterface $product): string
    {
        try {
            if ($product instanceof Product && $product->getImage() && $product->getImage() !== 'no_selection') {
                return $this->imageHelper->init($product, 'product_page_image_large')->getUrl();
            }
        } catch (\Throwable $exception) {
        }

        return '';
    }

    private function getAttributes(ProductInterface $product): array
    {
        if (!$product instanceof Product) {
            return [];
        }

        $attributes = [];
        foreach ($product->getAttributes() as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            if (in_array($code, self::IGNORED_ATTRIBUTES, true)) {
                continue;
            }

            $label = trim((string) ($attribute->getStoreLabel() ?: $attribute->getFrontendLabel() ?: $code));
            $options = $this->resolveAttributeOptions($product, $code);
            if ($label === '' || $options === []) {
                continue;
            }

            $attributes[] = [
                'name' => $label,
                'options' => $options,
            ];
        }

        return $attributes;
    }

    private function getProductSku(ProductInterface $product): string
    {
        return (string) ($product->getSku() ?: '');
    }

    private function getProductBrand(ProductInterface $product): string
    {
        if (!$product instanceof Product) {
            return '';
        }

        try {
            foreach (['brand', 'manufacturer', 'brand_name', 'product_brand'] as $attributeCode) {
                $value = $product->getAttributeText($attributeCode) ?: $product->getData($attributeCode);
                if (!$value) {
                    continue;
                }

                return is_array($value) ? implode(', ', $value) : (string) $value;
            }
        } catch (\Throwable $exception) {
        }

        return '';
    }

    private function extractGender(ProductInterface $product): string
    {
        if ($product instanceof Product) {
            foreach (['gender', 'sex'] as $attributeCode) {
                $value = $product->getAttributeText($attributeCode) ?: $product->getData($attributeCode);
                if (!$value) {
                    continue;
                }

                $raw = strtolower(is_array($value) ? implode(' ', $value) : (string) $value);
                if (str_contains($raw, 'men')) {
                    return 'Men';
                }
                if (str_contains($raw, 'women')) {
                    return 'Women';
                }
                if (str_contains($raw, 'kids')) {
                    return 'Kids';
                }
            }
        }

        return '';
    }

    private function getPriceInfo(ProductInterface $product): array
    {
        $regular = (float) $product->getPrice();
        $final = method_exists($product, 'getFinalPrice') ? (float) $product->getFinalPrice() : $regular;
        $sale = $final < $regular ? $final : 0.0;

        return [
            'price' => $final,
            'regular_price' => $regular,
            'sale_price' => $sale,
            'on_sale' => $sale > 0,
        ];
    }

    private function getStockStatus(ProductInterface $product): string
    {
        try {
            $stock = $this->stockRegistry->getStockItem((int) $product->getId());

            return $stock->getIsInStock() ? 'instock' : 'outofstock';
        } catch (\Throwable $exception) {
            return 'outofstock';
        }
    }

    private function getAverageRating(ProductInterface $product): float
    {
        try {
            if (method_exists($product, 'getRatingSummary')) {
                $summary = $product->getRatingSummary();

                return $summary ? round((float) $summary->getRating() / 20, 1) : 0.0;
            }
        } catch (\Throwable $exception) {
        }

        return 0.0;
    }

    private function getProductUrl(ProductInterface $product): string
    {
        try {
            if ($product instanceof Product) {
                return (string) $product->getProductUrl();
            }
        } catch (\Throwable $exception) {
        }

        return '';
    }

    private function getProductTags(ProductInterface $product): string
    {
        if ($product instanceof Product) {
            foreach (['tags', 'product_tags', 'search_tags'] as $attributeCode) {
                $value = $product->getAttributeText($attributeCode) ?: $product->getData($attributeCode);
                if (!$value) {
                    continue;
                }

                $tags = is_array($value) ? $value : explode(',', (string) $value);
                $tags = array_values(array_filter(array_map('trim', $tags)));
                if ($tags !== []) {
                    return implode(' ', array_unique($tags));
                }
            }
        }

        $categories = array_filter(array_map('trim', explode(',', $this->getCategoryHierarchy($product))));

        return implode(' ', array_unique($categories));
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

        if (is_scalar($value)) {
            return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
        }

        return [];
    }
}
