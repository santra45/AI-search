<?php
namespace Czar\AiChatbot\Model;

use Czar\AiChatbot\Helper\Config;
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

    public function syncBatch(array $items, int $batchNumber = 1, int $totalBatches = 1, ?int $storeId = null): array
    {
        return $this->post('/api/magento/chatbot/sync/batch', [
            'items' => $items,
            'batch_number' => $batchNumber,
            'total_batches' => $totalBatches,
        ], $storeId, true);
    }

    public function deleteItems(array $items, ?int $storeId = null): array
    {
        return $this->post('/api/magento/chatbot/sync/delete', ['items' => $items], $storeId, false);
    }

    public function getSyncStatus(?int $storeId = null): array
    {
        return $this->get('/api/magento/chatbot/sync/status', [], $storeId, false);
    }

    public function getContentCounts(?int $storeId = null): array
    {
        return $this->get('/api/magento/chatbot/sync/content-counts', [], $storeId, false);
    }

    public function startSession(array $payload, ?int $storeId = null): array
    {
        return $this->post('/api/magento/chatbot/session/start', $payload, $storeId, false);
    }

    public function sendMessage(array $payload, ?int $storeId = null): array
    {
        return $this->post('/api/magento/chatbot/message', $payload, $storeId, true);
    }

    public function getHistory(array $query, ?int $storeId = null): array
    {
        return $this->get('/api/magento/chatbot/history', $query, $storeId, false);
    }

    public function getConversations(array $query = [], ?int $storeId = null): array
    {
        return $this->get('/api/magento/chatbot/conversations', $query, $storeId, false);
    }

    public function getUsageData(int $days = 30, ?int $storeId = null): array
    {
        return $this->get('/api/magento/chatbot/usage', ['days' => $days], $storeId, false);
    }

    public function getDashboardData(array $query = [], ?int $storeId = null): array
    {
        return $this->get('/api/magento/chatbot/dashboard', $query, $storeId, false);
    }

    public function resetSession(array $payload, ?int $storeId = null): array
    {
        return $this->post('/api/magento/chatbot/reset', $payload, $storeId, false);
    }

    public function testConnection(?int $storeId = null): array
    {
        return $this->post('/api/test-connection', [
            'llm_provider' => $this->config->getLlmProvider($storeId),
            'llm_model' => $this->config->getLlmModel($storeId),
        ], $storeId, true);
    }

    private function get(string $path, array $query, ?int $storeId, bool $includeLlmHeaders): array
    {
        return $this->request('GET', $path, $query, $storeId, $includeLlmHeaders);
    }

    private function post(string $path, array $payload, ?int $storeId, bool $includeLlmHeaders): array
    {
        return $this->request('POST', $path, $payload, $storeId, $includeLlmHeaders);
    }

    private function request(string $method, string $path, array $payload, ?int $storeId, bool $includeLlmHeaders): array
    {
        $baseUrl = $this->config->getApiUrl($storeId);
        if ($baseUrl === '') {
            throw new LocalizedException(__('AI Chatbot API URL is not configured.'));
        }

        $url = $baseUrl . $path;
        if ($method === 'GET' && $payload !== []) {
            $url .= '?' . http_build_query($payload);
        }

        try {
            $this->curl->setHeaders($this->buildHeaders($storeId, $includeLlmHeaders));
            $this->curl->setTimeout($method === 'POST' ? 120 : 30);

            if ($method === 'POST') {
                $this->curl->post($url, json_encode($payload));
            } else {
                $this->curl->get($url);
            }

            $status = $this->curl->getStatus();
            $body = json_decode((string) $this->curl->getBody(), true) ?: [];
            if ($status < 200 || $status >= 300) {
                $message = $body['detail'] ?? $body['message'] ?? __('Unexpected API response.');
                throw new LocalizedException(__('AI Chatbot API error: %1', $message));
            }

            return $body;
        } catch (\Throwable $exception) {
            $this->logger->error('AI Chatbot API request failed.', [
                'message' => $exception->getMessage(),
                'url' => $url,
                'method' => $method,
            ]);

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
            'User-Agent' => 'Czar-AiChatbot-Magento/1.0',
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
            $encryptedKey = $this->config->getLlmApiKey($storeId);
            if ($encryptedKey !== '') {
                $headers['X-LLM-API-Key-Encrypted'] = $encryptedKey;
            }
        }

        return $headers;
    }

    private function resolveOrigin(?int $storeId): string
    {
        $store = $storeId !== null
            ? $this->storeManager->getStore($storeId)
            : $this->storeManager->getDefaultStoreView();

        return rtrim((string) $store->getBaseUrl(), '/');
    }
}
