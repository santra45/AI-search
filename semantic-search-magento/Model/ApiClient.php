<?php
namespace Czar\SemanticSearch\Model;

use Czar\SemanticSearch\Helper\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ApiClient
{
    public function __construct(
        private Config $config,
        private Curl $curl,
        private StoreManagerInterface $storeManager,
        private LoggerInterface $logger
    ) {
    }

    public function search(string $query, ?int $storeId = null): array
    {
        $payload = [
            'query' => $query,
            'limit' => $this->config->getResultLimit($storeId),
            'enable_intent' => $this->config->isIntentEnabled($storeId),
            'llm_provider' => $this->config->getLLMProvider($storeId),
            'llm_model' => $this->config->getLLMModel($storeId),
        ];

        try {
            return $this->post('/api/magento/search', $payload, $storeId, true)['results'] ?? [];
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'Semantic search request failed, default Magento search will be used.',
                ['message' => $exception->getMessage()]
            );

            return [];
        }
    }

    public function syncBatch(array $products, int $batchNumber = 1, int $totalBatches = 1, ?int $storeId = null): array
    {
        return $this->post(
            '/api/magento/sync/batch',
            [
                'products' => $products,
                'batch_number' => $batchNumber,
                'total_batches' => $totalBatches,
            ],
            $storeId,
            true
        );
    }

    public function deleteProduct(string $productId, ?int $storeId = null): array
    {
        return $this->post(
            '/api/magento/sync/delete',
            ['product_id' => $productId],
            $storeId,
            false
        );
    }

    public function testConnection(?int $storeId = null): array
    {
        return $this->post(
            '/api/test-connection',
            [
                'llm_provider' => $this->config->getLLMProvider($storeId),
                'llm_model' => $this->config->getLLMModel($storeId),
            ],
            $storeId,
            true
        );
    }

    public function getDashboardStats(?int $storeId = null): array
    {
        return $this->get('/api/dashboard/stats', [], $storeId);
    }

    public function getAnalyticsData(int $days, ?int $storeId = null): array
    {
        return [
            'summary' => $this->get('/api/analytics/summary', ['days' => $days], $storeId),
            'top_queries' => $this->get('/api/analytics/top-queries', ['days' => $days], $storeId),
            'zero_results' => $this->get('/api/analytics/zero-results', ['days' => $days], $storeId),
        ];
    }

    public function getUsageData(?int $storeId = null): array
    {
        return [
            'summary' => $this->get('/api/token-usage/me/summary', [], $storeId),
            'models' => $this->get('/api/token-usage/me/models', [], $storeId),
            'hourly' => $this->get('/api/token-usage/me/hourly', ['hours_back' => 24], $storeId),
            'stats' => $this->get('/api/token-usage/me/stats', [], $storeId),
        ];
    }

    public function getStatus(?int $storeId = null): array
    {
        return $this->get('/api/status', [], $storeId);
    }

    private function get(string $path, array $query = [], ?int $storeId = null): array
    {
        return $this->request('GET', $path, $query, $storeId, false);
    }

    private function post(string $path, array $payload, ?int $storeId, bool $includeLlmHeaders): array
    {
        return $this->request('POST', $path, $payload, $storeId, $includeLlmHeaders);
    }

    private function request(
        string $method,
        string $path,
        array $payload,
        ?int $storeId,
        bool $includeLlmHeaders
    ): array {
        $baseUrl = $this->config->getApiUrl($storeId);
        if ($baseUrl === '') {
            throw new LocalizedException(__('Semantic Search API URL is not configured.'));
        }

        $url = $baseUrl . $path;
        if ($method === 'GET' && $payload !== []) {
            $url .= '?' . http_build_query($payload);
        }

        try {
            $this->curl->setHeaders($this->buildHeaders($storeId, $includeLlmHeaders));
            $this->curl->setTimeout($method === 'POST' ? 120 : 20);

            if ($method === 'POST') {
                $this->curl->post($url, json_encode($payload));
            } else {
                $this->curl->get($url);
            }

            $status = $this->curl->getStatus();
            $body = json_decode((string) $this->curl->getBody(), true) ?: [];
            if ($status < 200 || $status >= 300) {
                $message = $body['detail'] ?? $body['message'] ?? __('Unexpected API response.');
                throw new LocalizedException(__('Semantic Search API error: %1', $message));
            }

            return $body;
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Semantic Search API request failed',
                ['message' => $exception->getMessage(), 'url' => $url, 'method' => $method]
            );

            throw $exception instanceof LocalizedException
                ? $exception
                : new LocalizedException(__($exception->getMessage()));
        }
    }

    private function buildHeaders(?int $storeId, bool $includeLlmHeaders): array
    {
        $origin = $this->resolveOrigin($storeId);
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->config->getLicenseKey($storeId),
            'Origin' => $origin,
            'Referer' => rtrim($origin, '/') . '/',
            'User-Agent' => 'Czar-SemanticSearch-Magento/1.0',
        ];

        $host = (string) parse_url($origin, PHP_URL_HOST);
        if ($host !== '') {
            $headers['X-Forwarded-Host'] = $host;
        }

        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey !== '') {
            $headers['X-API-Key'] = $apiKey;
        }

        if ($includeLlmHeaders) {
            $encryptedLlmKey = $this->config->getLLMApiKey($storeId);
            if ($encryptedLlmKey !== '') {
                $headers['X-LLM-API-Key-Encrypted'] = $encryptedLlmKey;
            }
        }

        return $headers;
    }

    private function resolveOrigin(?int $storeId): string
    {
        $store = $storeId !== null
            ? $this->storeManager->getStore($storeId)
            : $this->storeManager->getDefaultStoreView();

        return rtrim($store->getBaseUrl(), '/');
    }
}
