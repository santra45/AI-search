<?php
namespace Czar\SemanticSearch\Controller\Ajax;

use Czar\SemanticSearch\Model\ApiClient;
use Czar\SemanticSearch\Model\SearchResultProvider;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

class Search extends Action
{
    public function __construct(
        Context $context,
        private JsonFactory $resultJsonFactory,
        private ApiClient $apiClient,
        private SearchResultProvider $searchResultProvider,
        private StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $query = (string) $this->getRequest()->getParam('q', '');
        $resultJson = $this->resultJsonFactory->create();

        if (trim($query) === '') {
            return $resultJson->setData(['query' => $query, 'count' => 0, 'results' => []]);
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $apiResults = $this->apiClient->search($query, $storeId);
        $productIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['product_id'] ?? 0),
            $apiResults
        )));
        $results = $this->searchResultProvider->getProductsByIds($productIds, $storeId);

        return $resultJson->setData([
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ]);
    }
}
