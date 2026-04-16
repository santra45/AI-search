<?php
namespace Czar\AiChatbot\Block\Adminhtml;

use Czar\AiChatbot\Helper\Config;
use Czar\AiChatbot\Model\Sync\SyncService;
use Magento\Backend\Block\Template;
use Magento\Framework\Data\Form\FormKey;

class Dashboard extends Template
{
    protected $_template = 'Czar_AiChatbot::dashboard.phtml';

    public function __construct(
        Template\Context $context,
        private Config $config,
        private SyncService $syncService,
        private FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getInitialConfigJson(): string
    {
        return (string) json_encode([
            'ajaxUrl' => $this->getUrl('aichatbot/ajax/handle'),
            'formKey' => $this->formKey->getFormKey(),
            'settings' => [
                'enabled' => $this->config->isEnabled(),
                'api_url' => $this->config->getApiUrl(),
                'api_key' => $this->config->getApiKey(),
                'license_key' => $this->config->getLicenseKey(),
                'llm_provider' => $this->config->getLlmProvider(),
                'llm_model' => $this->config->getLlmModel(),
                'llm_api_key' => $this->config->getLlmApiKey() !== '' ? '**************************' : '',
                'welcome_message' => $this->config->getWelcomeMessage(),
                'fallback_message' => $this->config->getFallbackMessage(),
                'show_widget' => $this->config->isWidgetEnabled(),
                'position' => $this->config->getWidgetPosition(),
                'title' => $this->config->getWidgetTitle(),
                'theme_color' => $this->config->getThemeColor(),
                'starter_prompts' => implode('|', $this->config->getStarterPrompts()),
                'auto_sync_enabled' => $this->config->isAutoSyncEnabled(),
                'cron_expression' => $this->config->getAutoSyncCron(),
                'batch_size' => $this->config->getBatchSize(),
                'review_limit' => $this->config->getReviewLimit(),
                'sync_products' => $this->config->shouldSyncContentType('product'),
                'sync_cms_pages' => $this->config->shouldSyncContentType('cms_page'),
                'sync_cms_blocks' => $this->config->shouldSyncContentType('cms_block'),
                'sync_widgets' => $this->config->shouldSyncContentType('widget'),
                'sync_reviews' => $this->config->shouldSyncContentType('review'),
                'sync_policies' => $this->config->shouldSyncContentType('policy'),
                'sync_faq' => $this->config->shouldSyncContentType('faq'),
                'sync_store_config' => $this->config->shouldSyncContentType('store_config'),
                'retain_days' => $this->config->getHistoryRetentionDays(),
                'link_customer_sessions' => $this->config->shouldLinkCustomerSessions(),
            ],
            'sync' => $this->syncService->getProgress(),
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }
}
