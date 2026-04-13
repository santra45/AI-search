<?php
namespace Czar\SemanticSearch\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ENABLED = 'semantic_search/general/enabled';
    public const XML_PATH_API_URL = 'semantic_search/general/api_url';
    public const XML_PATH_API_KEY = 'semantic_search/general/api_key';
    public const XML_PATH_LICENSE_KEY = 'semantic_search/general/license_key';
    public const XML_PATH_RESULT_LIMIT = 'semantic_search/general/result_limit';

    public const XML_PATH_ENABLE_INTENT = 'semantic_search/llm_settings/enable_intent';
    public const XML_PATH_LLM_PROVIDER = 'semantic_search/llm_settings/llm_provider';
    public const XML_PATH_LLM_MODEL = 'semantic_search/llm_settings/llm_model';
    public const XML_PATH_LLM_API_KEY = 'semantic_search/llm_settings/llm_api_key';

    public const XML_PATH_AUTO_SYNC_ENABLED = 'semantic_search/sync/auto_sync_enabled';
    public const XML_PATH_AUTO_SYNC_CRON = 'semantic_search/sync/cron_expression';
    public const XML_PATH_BATCH_SIZE = 'semantic_search/sync/batch_size';

    public function __construct(private ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getApiUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->scopeConfig->getValue(self::XML_PATH_API_URL, ScopeInterface::SCOPE_STORE, $storeId), '/');
    }

    public function getApiKey(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getLicenseKey(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_LICENSE_KEY, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getResultLimit(?int $storeId = null): int
    {
        $limit = (int) $this->scopeConfig->getValue(self::XML_PATH_RESULT_LIMIT, ScopeInterface::SCOPE_STORE, $storeId);
        return $limit > 0 ? $limit : 10;
    }

    public function isIntentEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_INTENT, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getLLMProvider(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_LLM_PROVIDER, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getLLMModel(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_LLM_MODEL, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getLLMApiKey(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_LLM_API_KEY, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function isAutoSyncEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_AUTO_SYNC_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getAutoSyncCron(?int $storeId = null): string
    {
        $value = trim((string) $this->scopeConfig->getValue(self::XML_PATH_AUTO_SYNC_CRON, ScopeInterface::SCOPE_STORE, $storeId));

        return $value !== '' ? $value : '0 * * * *';
    }

    public function getBatchSize(?int $storeId = null): int
    {
        $size = (int) $this->scopeConfig->getValue(self::XML_PATH_BATCH_SIZE, ScopeInterface::SCOPE_STORE, $storeId);

        return $size > 0 ? $size : 20;
    }

    public function isConfigured(?int $storeId = null): bool
    {
        return $this->getApiUrl($storeId) !== '' && $this->getLicenseKey($storeId) !== '';
    }

    public function isSearchReady(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->isConfigured($storeId);
    }
}
