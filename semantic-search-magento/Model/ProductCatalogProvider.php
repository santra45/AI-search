<?php
namespace Czar\SemanticSearch\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;

class ProductCatalogProvider
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private FilterBuilder $filterBuilder,
        private FilterGroupBuilder $filterGroupBuilder
    ) {
    }

    public function getTotalCount(int $storeId = 0): int
    {
        return $this->createSearchResult(1, 1)->getTotalCount();
    }

    public function getProductsForPage(int $page, int $pageSize, int $storeId = 0): array
    {
        $searchResult = $this->createSearchResult($page, $pageSize);
        $products = [];

        foreach ($searchResult->getItems() as $item) {
            $products[] = $this->productRepository->getById(
                (int) $item->getId(),
                false,
                $storeId,
                true
            );
        }

        return $products;
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
}
