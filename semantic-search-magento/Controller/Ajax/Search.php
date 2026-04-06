<?php
namespace Czar\SemanticSearch\Controller\Ajax;

use Czar\SemanticSearch\Model\ApiClient;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class Search extends Action
{
    public function __construct(
        Context $context,
        private JsonFactory $resultJsonFactory,
        private ApiClient $apiClient
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

        $results = $this->apiClient->search($query);

        return $resultJson->setData([
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ]);
    }
}
