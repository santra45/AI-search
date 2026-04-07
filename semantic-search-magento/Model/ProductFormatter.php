<?php
namespace Czar\SemanticSearch\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Helper\Image;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Pricing\Helper\Data;
use Magento\Directory\Model\CurrencyFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;

class ProductFormatter
{
    public function __construct(
        private StoreManagerInterface $storeManager,
        private Image $imageHelper,
        private CategoryRepositoryInterface $categoryRepository,
        private Data $priceHelper,
        private CurrencyFactory $currencyFactory,
        private StockRegistryInterface $stockRegistry
    ) {
    }

    public function format(ProductInterface $product): array
    {
        $productId = $product->getId();
        $productName = $product->getName();
        $sku = $this->getProductSku($product);

        // ✅ Store currency (FIXED)
        $storeId = method_exists($product, 'getStoreId') ? $product->getStoreId() : null;
        $store = $this->storeManager->getStore($storeId);
        $currency = $store->getCurrentCurrency();

        return [
            'sku' => $sku,
            'product_id' => (string)$productId,
            'name' => (string)$productName,
            'categories' => $this->getCategoryHierarchy($product),
            'tags' => $this->getProductTags($product),
            'gender' => $this->extractGender($product),
            'description' => (string)($product->getCustomAttribute('description')?->getValue() ?? ''),
            'brand' => $this->getProductBrand($product),
            'short_description' => (string)($product->getCustomAttribute('short_description')?->getValue() ?? ''),
            'price' => $this->getPriceInfo($product)['price'],
            'currency' => $currency->getCode(),
            'currency_symbol' => $currency->getCurrencySymbol(),
            'regular_price' => $this->getPriceInfo($product)['regular_price'],
            'sale_price' => $this->getPriceInfo($product)['sale_price'],
            'on_sale' => $this->getPriceInfo($product)['on_sale'],
            'permalink' => $this->getProductUrl($product),
            'image_url' => $this->getProductImageUrl($product),
            'stock_status' => $this->getStockStatus($product),
            'average_rating' => $this->getAverageRating($product),
            'attributes' => $this->getEnhancedAttributes($product),
        ];
    }

    private function getCategoryHierarchy(ProductInterface $product): string
    {
        $categoryIds = method_exists($product, 'getCategoryIds') ? $product->getCategoryIds() : [];
        $paths = [];

        foreach ($categoryIds as $categoryId) {
            try {
                $category = $this->categoryRepository->get($categoryId);
                $pathIds = explode('/', $category->getPath());
                $names = [];

                foreach ($pathIds as $id) {
                    if ($id <= 2) continue; // skip root & default
                    $cat = $this->categoryRepository->get($id);
                    $names[] = $cat->getName();
                }

                if ($names) {
                    $paths[] = implode(' > ', $names);
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return implode(', ', $paths);
    }

    private function getProductImageUrl(ProductInterface $product): string
    {
        try {
            if ($product instanceof Product && $product->getImage() && $product->getImage() !== 'no_selection') {
                return $this->imageHelper
                    ->init($product, 'product_page_image_large')
                    ->getUrl();
            }
        } catch (\Exception $e) {}

        return '';
    }

    private function getEnhancedAttributes(ProductInterface $product): array
    {
        $attributes = [];

        foreach ($product->getCustomAttributes() ?? [] as $attribute) {
            $value = $attribute->getValue();
            if (!$value) continue;

            $code = $attribute->getAttributeCode();

            if (in_array($code, ['description', 'short_description', 'price', 'special_price'])) {
                continue;
            }

            $options = is_array($value) ? $value : explode(',', (string)$value);

            $attributes[] = [
                'name' => $code,
                'label' => $code,
                'options' => array_values(array_filter(array_map('trim', $options))),
            ];
        }

        return $attributes;
    }

    private function getProductSku(ProductInterface $product): string
    {
        return $product->getSku() ?: '';
    }

    private function getProductBrand(ProductInterface $product): string
    {
        try {
            if ($product instanceof Product) {
                $brand = $product->getAttributeText('brand');
                if ($brand) return (string)$brand;

                foreach (['manufacturer', 'brand_name', 'product_brand'] as $attr) {
                    $value = $product->getAttributeText($attr);
                    if ($value) return (string)$value;
                }
            }
        } catch (\Exception $e) {}

        return '';
    }

    private function extractGender(ProductInterface $product): string
    {
        foreach (['gender', 'sex'] as $attrCode) {
            $attr = $product->getCustomAttribute($attrCode);
            if ($attr && $attr->getValue()) {
                $value = strtolower($attr->getValue());

                if (str_contains($value, 'men')) return 'Men';
                if (str_contains($value, 'women')) return 'Women';
                if (str_contains($value, 'kids')) return 'Kids';
            }
        }

        return '';
    }

    private function getPriceInfo(ProductInterface $product): array
    {
        $regular = (float)$product->getPrice();
        $final = method_exists($product, 'getFinalPrice') ? (float)$product->getFinalPrice() : $regular;

        $sale = $final < $regular ? $final : 0;

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
            $stock = $this->stockRegistry->getStockItem($product->getId());
            return $stock->getIsInStock() ? 'instock' : 'outofstock';
        } catch (\Exception $e) {
            return 'outofstock';
        }
    }

    private function getAverageRating(ProductInterface $product): float
    {
        try {
            if (method_exists($product, 'getRatingSummary')) {
                $summary = $product->getRatingSummary();
                return $summary ? round($summary->getRating() / 20, 1) : 0;
            }
        } catch (\Exception $e) {}

        return 0;
    }

    private function getProductUrl(ProductInterface $product): string
    {
        try {
            if ($product instanceof Product) {
                return $product->getProductUrl();
            }
        } catch (\Exception $e) {}

        return '';
    }

    private function getProductTags(ProductInterface $product): string
    {
        $tags = [];

        $tagAttr = $product->getCustomAttribute('tags');
        if ($tagAttr) {
            $tags = explode(',', $tagAttr->getValue());
        }

        $categories = $this->getCategoryHierarchy($product);
        if ($categories) {
            $tags = array_merge($tags, explode(',', $categories));
        }

        return implode(', ', array_unique(array_map('trim', $tags)));
    }
}