<?php
namespace Czar\SemanticSearch\Model;

use Magento\Catalog\Api\Data\ProductInterface;

class ProductFormatter
{
    public function format(ProductInterface $product): array
    {
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

        return [
            'product_id' => (string) $product->getId(),
            'name' => (string) $product->getName(),
            'categories' => '',
            'tags' => '',
            'description' => (string) ($product->getCustomAttribute('description')?->getValue() ?? ''),
            'short_description' => (string) ($product->getCustomAttribute('short_description')?->getValue() ?? ''),
            'price' => (float) $product->getPrice(),
            'regular_price' => (float) $product->getPrice(),
            'sale_price' => (float) $product->getFinalPrice(),
            'currency' => '',
            'currency_symbol' => '',
            'on_sale' => (float) $product->getFinalPrice() < (float) $product->getPrice(),
            'permalink' => '',
            'image_url' => '',
            'stock_status' => 'instock',
            'average_rating' => 0,
            'attributes' => $attributes,
        ];
    }
}
