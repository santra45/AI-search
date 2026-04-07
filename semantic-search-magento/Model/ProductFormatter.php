<?php
namespace Czar\SemanticSearch\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;

class ProductFormatter
{
    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
        private StoreManagerInterface $storeManager,
        private PriceCurrencyInterface $priceCurrency,
        private MediaConfig $mediaConfig
    ) {
    }

    public function format(ProductInterface $product): array
    {
        $store = $this->storeManager->getStore();
        $currency = $store->getCurrentCurrency();

        $attributes = [];
        foreach ($product->getCustomAttributes() ?? [] as $attribute) {
            $value = $attribute->getValue();
            if ($value === null || $value === '') {
                continue;
            }

            $options = is_array($value) ? $value : explode(',', (string) $value);
            $attributes[] = [
                'name' => (string) $attribute->getAttributeCode(),
                'options' => array_values(array_filter(array_map('trim', $options))),
            ];
        }

        $categoryNames = [];
        foreach ($product->getCategoryIds() as $categoryId) {
            try {
                $category = $this->categoryRepository->get((int) $categoryId, $store->getId());
                $categoryNames[] = $category->getName();
            } catch (\Throwable) {
                continue;
            }
        }

        $image = (string) $product->getData('image');
        $imageUrl = '';
        if ($image !== '' && $image !== 'no_selection') {
            $imageUrl = $store->getBaseUrl() . $this->mediaConfig->getMediaUrl($image);
        }

        $regularPrice = (float) ($product->getPrice() ?? 0);
        $finalPrice = (float) ($product->getFinalPrice() ?? $regularPrice);

        return [
            'product_id' => (string) $product->getId(),
            'name' => (string) $product->getName(),
            'categories' => implode(' > ', array_unique($categoryNames)),
            'tags' => (string) ($product->getCustomAttribute('meta_keyword')?->getValue() ?? ''),
            'description' => (string) ($product->getCustomAttribute('description')?->getValue() ?? ''),
            'short_description' => (string) ($product->getCustomAttribute('short_description')?->getValue() ?? ''),
            'price' => $finalPrice,
            'regular_price' => $regularPrice,
            'sale_price' => $regularPrice > $finalPrice ? $finalPrice : 0.0,
            'currency' => (string) $currency->getCurrencyCode(),
            'currency_symbol' => (string) $this->priceCurrency->getCurrencySymbol(),
            'on_sale' => $regularPrice > $finalPrice,
            'permalink' => (string) $product->getProductUrl(),
            'image_url' => $imageUrl,
            'stock_status' => (int) $product->getStatus() === 1 ? 'instock' : 'outofstock',
            'average_rating' => 0,
            'attributes' => $attributes,
        ];
    }
}
