<?php
namespace Czar\SemanticSearch\Model;

use Czar\SemanticSearch\Helper\Config;
use Czar\SemanticSearch\Helper\Encryption;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class ApiClient
{
    public function __construct(
        private Config $config,
        private Encryption $encryption,
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
        ];

        // Add intent detection if enabled
        if ($this->config->isIntentEnabled($storeId)) {
            $payload['enable_intent'] = true;
        }

        // Add LLM configuration if set
        $llmProvider = $this->config->getLLMProvider($storeId);
        $llmModel = $this->config->getLLMModel($storeId);
        $llmApiKey = $this->config->getLLMApiKey($storeId);

        if ($llmProvider && $llmModel && $llmApiKey) {
            $payload['llm_provider'] = $llmProvider;
            $payload['llm_model'] = $llmModel;
            // Encrypt the API key using the same method as WordPress
            $payload['llm_api_key_encrypted'] = $this->encryption->encryptApiKey($llmApiKey, $this->config->getLicenseKey($storeId));
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

        // Add encrypted LLM API key if available for embedding operations
        $llmApiKey = $this->config->getLLMApiKey($storeId);
        if ($llmApiKey) {
            $payload['llm_api_key_encrypted'] = $this->encryption->encryptApiKey($llmApiKey, $this->config->getLicenseKey($storeId));
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

    private function post(string $path, array $payload): array
    {
        $url = $this->config->getApiUrl() . $path;

        try {
            $this->curl->setHeaders(['Content-Type' => 'application/json']);
            $this->curl->setTimeout(20);
            $this->curl->post($url, json_encode($payload));

            $status = $this->curl->getStatus();
            $body = json_decode((string) $this->curl->getBody(), true) ?: [];

            if ($status < 200 || $status >= 300) {
                $this->logger->error('Semantic Search API returned non-2xx', ['status' => $status, 'url' => $url, 'response' => $body]);
                return [];
            }

            return $body;
        } catch (\Throwable $e) {
            $this->logger->error('Semantic Search API request failed', ['message' => $e->getMessage(), 'url' => $url]);
            return [];
        }
    }
}
