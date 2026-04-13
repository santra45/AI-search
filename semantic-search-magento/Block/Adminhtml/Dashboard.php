<?php
namespace Czar\SemanticSearch\Block\Adminhtml;

use Czar\SemanticSearch\Helper\Config;
use Czar\SemanticSearch\Model\SyncService;
use Magento\Backend\Block\Template;
use Magento\Framework\Data\Form\FormKey;

class Dashboard extends Template
{
    protected $_template = 'Czar_SemanticSearch::dashboard.phtml';

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
        $payload = [
            'ajaxUrl' => $this->getUrl('semanticsearch/ajax/handle'),
            'formKey' => $this->formKey->getFormKey(),
            'settings' => [
                'enabled' => $this->config->isEnabled(),
                'api_url' => $this->config->getApiUrl(),
                'api_key' => $this->config->getApiKey(),
                'license_key' => $this->config->getLicenseKey(),
                'result_limit' => $this->config->getResultLimit(),
                'enable_intent' => $this->config->isIntentEnabled(),
                'llm_provider' => $this->config->getLLMProvider(),
                'llm_model' => $this->config->getLLMModel(),
                'llm_api_key' => $this->config->getLLMApiKey() !== '' ? '**************************' : '',
                'auto_sync_enabled' => $this->config->isAutoSyncEnabled(),
                'cron_expression' => $this->config->getAutoSyncCron(),
                'batch_size' => $this->config->getBatchSize(),
            ],
            'sync' => $this->syncService->getProgress(),
        ];

        return (string) json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }
}
