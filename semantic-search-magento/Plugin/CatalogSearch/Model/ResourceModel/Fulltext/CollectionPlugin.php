<?php
namespace Czar\SemanticSearch\Plugin\CatalogSearch\Model\ResourceModel\Fulltext;

use Czar\SemanticSearch\Helper\Config;
use Czar\SemanticSearch\Model\ApiClient;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CollectionPlugin
{
    public function __construct(
        private Config $config,
        private ApiClient $apiClient,
        private StoreManagerInterface $storeManager,
        private RequestInterface $request,
        private State $appState,
        private LoggerInterface $logger
    ) {
    }

    public function aroundAddSearchFilter(Collection $subject, callable $proceed, $query)
    {
        try {
            if (!$this->shouldIntercept((string) $query)) {
                return $proceed($query);
            }

            $storeId = (int) $this->storeManager->getStore()->getId();
            $results = $this->apiClient->search((string) $query, $storeId);
            $productIds = array_values(array_filter(array_map(
                static fn(array $row): int => (int) ($row['product_id'] ?? 0),
                $results
            )));

            if ($productIds === []) {
                return $proceed($query);
            }

            $subject->addAttributeToFilter('entity_id', ['in' => $productIds]);
            $subject->getSelect()->reset(\Magento\Framework\DB\Select::ORDER);
            $subject->getSelect()->order(
                new \Zend_Db_Expr('FIELD(e.entity_id, ' . implode(',', array_map('intval', $productIds)) . ')')
            );

            return $subject;
        } catch (\Throwable $exception) {
            $this->logger->warning('Semantic search interception failed, using default Magento search.', [
                'message' => $exception->getMessage(),
            ]);

            return $proceed($query);
        }
    }

    private function shouldIntercept(string $query): bool
    {
        if (trim($query) === '') {
            return false;
        }

        try {
            if ($this->appState->getAreaCode() !== 'frontend') {
                return false;
            }
        } catch (\Throwable $exception) {
            return false;
        }

        if ($this->request->getFullActionName() === 'catalogsearch_result_index') {
            return $this->config->isSearchReady((int) $this->storeManager->getStore()->getId());
        }

        return false;
    }
}
