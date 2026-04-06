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
}
