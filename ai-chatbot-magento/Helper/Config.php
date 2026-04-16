<?php
namespace Czar\AiChatbot\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ENABLED = 'ai_chatbot/general/enabled';
    public const XML_PATH_API_URL = 'ai_chatbot/general/api_url';
    public const XML_PATH_API_KEY = 'ai_chatbot/general/api_key';
    public const XML_PATH_LICENSE_KEY = 'ai_chatbot/general/license_key';
    public const XML_PATH_LLM_PROVIDER = 'ai_chatbot/general/llm_provider';
    public const XML_PATH_LLM_MODEL = 'ai_chatbot/general/llm_model';
    public const XML_PATH_LLM_API_KEY = 'ai_chatbot/general/llm_api_key';
    public const XML_PATH_WELCOME_MESSAGE = 'ai_chatbot/general/welcome_message';
    public const XML_PATH_FALLBACK_MESSAGE = 'ai_chatbot/general/fallback_message';

    public const XML_PATH_WIDGET_ENABLED = 'ai_chatbot/widget/show_widget';
    public const XML_PATH_WIDGET_POSITION = 'ai_chatbot/widget/position';
    public const XML_PATH_WIDGET_TITLE = 'ai_chatbot/widget/title';
    public const XML_PATH_WIDGET_THEME_COLOR = 'ai_chatbot/widget/theme_color';
    public const XML_PATH_WIDGET_STARTER_PROMPTS = 'ai_chatbot/widget/starter_prompts';

    public const XML_PATH_AUTO_SYNC_ENABLED = 'ai_chatbot/sync/auto_sync_enabled';
    public const XML_PATH_AUTO_SYNC_CRON = 'ai_chatbot/sync/cron_expression';
    public const XML_PATH_BATCH_SIZE = 'ai_chatbot/sync/batch_size';
    public const XML_PATH_REVIEW_LIMIT = 'ai_chatbot/sync/review_limit';
    public const XML_PATH_SYNC_PRODUCTS = 'ai_chatbot/sync/sync_products';
    public const XML_PATH_SYNC_CMS_PAGES = 'ai_chatbot/sync/sync_cms_pages';
    public const XML_PATH_SYNC_CMS_BLOCKS = 'ai_chatbot/sync/sync_cms_blocks';
    public const XML_PATH_SYNC_WIDGETS = 'ai_chatbot/sync/sync_widgets';
    public const XML_PATH_SYNC_REVIEWS = 'ai_chatbot/sync/sync_reviews';
    public const XML_PATH_SYNC_POLICIES = 'ai_chatbot/sync/sync_policies';
    public const XML_PATH_SYNC_FAQ = 'ai_chatbot/sync/sync_faq';
    public const XML_PATH_SYNC_STORE_CONFIG = 'ai_chatbot/sync/sync_store_config';

    public const XML_PATH_RETAIN_DAYS = 'ai_chatbot/history/retain_days';
    public const XML_PATH_LINK_CUSTOMER_SESSIONS = 'ai_chatbot/history/link_customer_sessions';

    private const FALLBACKS = [
        self::XML_PATH_API_URL => 'semantic_search/general/api_url',
        self::XML_PATH_API_KEY => 'semantic_search/general/api_key',
        self::XML_PATH_LICENSE_KEY => 'semantic_search/general/license_key',
        self::XML_PATH_LLM_PROVIDER => 'semantic_search/llm_settings/llm_provider',
        self::XML_PATH_LLM_MODEL => 'semantic_search/llm_settings/llm_model',
        self::XML_PATH_LLM_API_KEY => 'semantic_search/llm_settings/llm_api_key',
    ];

    public function __construct(private ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isWidgetEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_WIDGET_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getApiUrl(?int $storeId = null): string
    {
        return rtrim($this->getValue(self::XML_PATH_API_URL, $storeId), '/');
    }

    public function getApiKey(?int $storeId = null): string
    {
        return trim($this->getValue(self::XML_PATH_API_KEY, $storeId));
    }

    public function getLicenseKey(?int $storeId = null): string
    {
        return trim($this->getValue(self::XML_PATH_LICENSE_KEY, $storeId));
    }

    public function getLlmProvider(?int $storeId = null): string
    {
        return trim($this->getValue(self::XML_PATH_LLM_PROVIDER, $storeId));
    }

    public function getLlmModel(?int $storeId = null): string
    {
        return trim($this->getValue(self::XML_PATH_LLM_MODEL, $storeId));
    }

    public function getLlmApiKey(?int $storeId = null): string
    {
        return trim($this->getValue(self::XML_PATH_LLM_API_KEY, $storeId));
    }

    public function getWelcomeMessage(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_WELCOME_MESSAGE, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getFallbackMessage(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_FALLBACK_MESSAGE, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getWidgetTitle(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_WIDGET_TITLE, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getWidgetPosition(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_WIDGET_POSITION, ScopeInterface::SCOPE_STORE, $storeId)) ?: 'right';
    }

    public function getThemeColor(?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_WIDGET_THEME_COLOR, ScopeInterface::SCOPE_STORE, $storeId)) ?: '#0f4c81';
    }

    public function getStarterPrompts(?int $storeId = null): array
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_WIDGET_STARTER_PROMPTS, ScopeInterface::SCOPE_STORE, $storeId);

        return array_values(array_filter(array_map('trim', preg_split('/[\r\n|]+/', $value ?: ''))));
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
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_BATCH_SIZE, ScopeInterface::SCOPE_STORE, $storeId);

        return $value > 0 ? $value : 20;
    }

    public function getReviewLimit(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_REVIEW_LIMIT, ScopeInterface::SCOPE_STORE, $storeId);

        return $value > 0 ? $value : 5;
    }

    public function getHistoryRetentionDays(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_RETAIN_DAYS, ScopeInterface::SCOPE_STORE, $storeId);

        return $value > 0 ? $value : 30;
    }

    public function shouldLinkCustomerSessions(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LINK_CUSTOMER_SESSIONS, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function shouldSyncContentType(string $contentType, ?int $storeId = null): bool
    {
        $path = match ($contentType) {
            'product' => self::XML_PATH_SYNC_PRODUCTS,
            'cms_page' => self::XML_PATH_SYNC_CMS_PAGES,
            'cms_block' => self::XML_PATH_SYNC_CMS_BLOCKS,
            'widget' => self::XML_PATH_SYNC_WIDGETS,
            'review' => self::XML_PATH_SYNC_REVIEWS,
            'policy' => self::XML_PATH_SYNC_POLICIES,
            'faq' => self::XML_PATH_SYNC_FAQ,
            'store_config' => self::XML_PATH_SYNC_STORE_CONFIG,
            default => null,
        };

        return $path ? $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId) : false;
    }

    public function isConfigured(?int $storeId = null): bool
    {
        return $this->getApiUrl($storeId) !== '' && $this->getLicenseKey($storeId) !== '';
    }

    public function isChatReady(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->isConfigured($storeId);
    }

    private function getValue(string $path, ?int $storeId = null): string
    {
        $value = trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));
        if ($value !== '') {
            return $value;
        }

        $fallbackPath = self::FALLBACKS[$path] ?? null;
        if ($fallbackPath === null) {
            return '';
        }

        return trim((string) $this->scopeConfig->getValue($fallbackPath, ScopeInterface::SCOPE_STORE, $storeId));
    }
}
