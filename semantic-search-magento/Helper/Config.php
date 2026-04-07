<?php
namespace Czar\SemanticSearch\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED = 'semantic_search/general/enabled';
    private const XML_PATH_API_URL = 'semantic_search/general/api_url';
    private const XML_PATH_LICENSE_KEY = 'semantic_search/general/license_key';
    private const XML_PATH_RESULT_LIMIT = 'semantic_search/general/result_limit';
    
    private const XML_PATH_ENABLE_INTENT = 'semantic_search/llm_settings/enable_intent';
    private const XML_PATH_LLM_PROVIDER = 'semantic_search/llm_settings/llm_provider';
    private const XML_PATH_LLM_MODEL = 'semantic_search/llm_settings/llm_model';
    private const XML_PATH_LLM_API_KEY = 'semantic_search/llm_settings/llm_api_key';

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
}
