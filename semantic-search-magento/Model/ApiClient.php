<?php
namespace Czar\SemanticSearch\Model;

use Czar\SemanticSearch\Helper\Config;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class ApiClient
{
    public function __construct(
        private Config $config,
        private Curl $curl,
        private LoggerInterface $logger
    ) {
    }

    public function search(string $query, ?int $storeId = null): array
    {
        $payload = [
            'license_key' => $this->config->getLicenseKey($storeId),
            'query' => $query,
            'limit' => $this->config->getResultLimit($storeId),
            'enable_intent' => $this->config->isIntentEnabled($storeId),
        ];

        $provider = $this->config->getLlmProvider($storeId);
        $model = $this->config->getLlmModel($storeId);
        $encryptedKey = $this->config->getLlmApiKeyEncrypted($storeId);

        if ($provider !== '' && $model !== '' && $encryptedKey !== '') {
            $payload['llm_provider'] = $provider;
            $payload['llm_model'] = $model;
            $payload['llm_api_key_encrypted'] = $encryptedKey;
        }

        return $this->post('/api/magento/search', $payload)['results'] ?? [];
    }

    public function syncBatch(array $products, int $batchNumber = 1, int $totalBatches = 1, ?int $storeId = null): array
    {
        $payload = [
            'license_key' => $this->config->getLicenseKey($storeId),
            'products' => $products,
            'batch_number' => $batchNumber,
            'total_batches' => $totalBatches,
        ];

        $encryptedKey = $this->config->getLlmApiKeyEncrypted($storeId);
        if ($encryptedKey !== '') {
            $payload['llm_api_key_encrypted'] = $encryptedKey;
        }

        return $this->post('/api/magento/sync/batch', $payload);
    }

    public function deleteProduct(string $productId, ?int $storeId = null): array
    {
        return $this->post('/api/magento/sync/delete', [
            'license_key' => $this->config->getLicenseKey($storeId),
            'product_id' => $productId,
        ]);
    }

    public function getDashboardStats(?int $storeId = null): array
    {
        return $this->get('/api/dashboard/stats', [], true, $storeId);
    }

    public function getAnalyticsSummary(int $days = 7, ?int $storeId = null): array
    {
        return $this->get('/api/analytics/summary', ['days' => $days], true, $storeId);
    }

    public function getTopQueries(int $days = 7, ?int $storeId = null): array
    {
        return $this->get('/api/analytics/top-queries', ['days' => $days], true, $storeId);
    }

    public function getZeroResults(int $days = 7, ?int $storeId = null): array
    {
        return $this->get('/api/analytics/zero-results', ['days' => $days], true, $storeId);
    }

    private function post(string $path, array $payload, bool $withBearer = false, ?int $storeId = null): array
    {
        $url = $this->config->getApiUrl($storeId) . $path;

        try {
            $headers = ['Content-Type' => 'application/json'];
            if ($withBearer) {
                $headers['Authorization'] = 'Bearer ' . $this->config->getLicenseKey($storeId);
            }

            $this->curl->setHeaders($headers);
            $this->curl->setTimeout(30);
            $this->curl->post($url, json_encode($payload));

            return $this->parseResponse($url);
        } catch (\Throwable $e) {
            $this->logger->error('Semantic Search API request failed', ['message' => $e->getMessage(), 'url' => $url]);
            return [];
        }
    }

    private function get(string $path, array $query = [], bool $withBearer = false, ?int $storeId = null): array
    {
        $queryString = $query ? '?' . http_build_query($query) : '';
        $url = $this->config->getApiUrl($storeId) . $path . $queryString;

        try {
            $headers = ['Content-Type' => 'application/json'];
            if ($withBearer) {
                $headers['Authorization'] = 'Bearer ' . $this->config->getLicenseKey($storeId);
            }

            $this->curl->setHeaders($headers);
            $this->curl->setTimeout(30);
            $this->curl->get($url);

            return $this->parseResponse($url);
        } catch (\Throwable $e) {
            $this->logger->error('Semantic Search API GET request failed', ['message' => $e->getMessage(), 'url' => $url]);
            return [];
        }
    }

    private function parseResponse(string $url): array
    {
        $status = $this->curl->getStatus();
        $body = json_decode((string) $this->curl->getBody(), true) ?: [];

        if ($status < 200 || $status >= 300) {
            $this->logger->error('Semantic Search API returned non-2xx', [
                'status' => $status,
                'url' => $url,
                'response' => $body,
            ]);
            return [];
        }

        return $body;
    }
}
