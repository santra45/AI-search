<?php
namespace Czar\SemanticSearch\Controller\Adminhtml\Ajax;

use Czar\SemanticSearch\Model\ApiClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class DashboardData extends Action
{
    public const ADMIN_RESOURCE = 'Czar_SemanticSearch::dashboard';

    public function __construct(
        Context $context,
        private JsonFactory $resultJsonFactory,
        private ApiClient $apiClient
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $days = (int) $this->getRequest()->getParam('days', 7);

        $stats = $this->apiClient->getDashboardStats();
        $summary = $this->apiClient->getAnalyticsSummary($days);
        $topQueries = $this->apiClient->getTopQueries($days);
        $zeroResults = $this->apiClient->getZeroResults($days);

        return $result->setData([
            'stats' => $stats,
            'summary' => $summary,
            'top_queries' => $topQueries['queries'] ?? [],
            'zero_results' => $zeroResults['queries'] ?? [],
        ]);
    }
}
