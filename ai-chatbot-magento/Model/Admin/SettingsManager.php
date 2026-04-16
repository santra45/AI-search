<?php
namespace Czar\AiChatbot\Model\Admin;

use Czar\AiChatbot\Helper\Config;
use Czar\AiChatbot\Helper\Encryption;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;

class SettingsManager
{
    public function __construct(
        private WriterInterface $configWriter,
        private TypeListInterface $cacheTypeList,
        private Config $config,
        private Encryption $encryption
    ) {
    }

    public function save(array $data): void
    {
        $licenseKey = trim((string) ($data['license_key'] ?? ''));
        $submittedLlmApiKey = trim((string) ($data['llm_api_key'] ?? ''));
        $encryptedLlmApiKey = $this->config->getLlmApiKey();

        if ($submittedLlmApiKey !== '' && $submittedLlmApiKey !== '**************************') {
            if ($licenseKey === '') {
                throw new LocalizedException(__('License key is required before saving an LLM API key.'));
            }

            $encryptedLlmApiKey = $this->encryption->encryptApiKey($submittedLlmApiKey, $licenseKey);
        }

        $this->configWriter->save(Config::XML_PATH_ENABLED, !empty($data['enabled']) ? 1 : 0);
        $this->configWriter->save(Config::XML_PATH_API_URL, rtrim(trim((string) ($data['api_url'] ?? '')), '/'));
        $this->configWriter->save(Config::XML_PATH_API_KEY, trim((string) ($data['api_key'] ?? '')));
        $this->configWriter->save(Config::XML_PATH_LICENSE_KEY, $licenseKey);
        $this->configWriter->save(Config::XML_PATH_LLM_PROVIDER, trim((string) ($data['llm_provider'] ?? '')));
        $this->configWriter->save(Config::XML_PATH_LLM_MODEL, trim((string) ($data['llm_model'] ?? '')));
        $this->configWriter->save(Config::XML_PATH_LLM_API_KEY, $encryptedLlmApiKey);
        $this->configWriter->save(Config::XML_PATH_WELCOME_MESSAGE, trim((string) ($data['welcome_message'] ?? '')));
        $this->configWriter->save(Config::XML_PATH_FALLBACK_MESSAGE, trim((string) ($data['fallback_message'] ?? '')));

        $this->configWriter->save(Config::XML_PATH_WIDGET_ENABLED, !empty($data['show_widget']) ? 1 : 0);
        $this->configWriter->save(Config::XML_PATH_WIDGET_POSITION, trim((string) ($data['position'] ?? 'right')));
        $this->configWriter->save(Config::XML_PATH_WIDGET_TITLE, trim((string) ($data['title'] ?? 'Store Assistant')));
        $this->configWriter->save(Config::XML_PATH_WIDGET_THEME_COLOR, trim((string) ($data['theme_color'] ?? '#0f4c81')));
        $this->configWriter->save(Config::XML_PATH_WIDGET_STARTER_PROMPTS, trim((string) ($data['starter_prompts'] ?? '')));

        $this->configWriter->save(Config::XML_PATH_AUTO_SYNC_ENABLED, !empty($data['auto_sync_enabled']) ? 1 : 0);
        $this->configWriter->save(Config::XML_PATH_AUTO_SYNC_CRON, trim((string) ($data['cron_expression'] ?? '0 * * * *')) ?: '0 * * * *');
        $this->configWriter->save(Config::XML_PATH_BATCH_SIZE, max(1, (int) ($data['batch_size'] ?? 20)));
        $this->configWriter->save(Config::XML_PATH_REVIEW_LIMIT, max(1, (int) ($data['review_limit'] ?? 5)));
        $this->configWriter->save(Config::XML_PATH_SYNC_PRODUCTS, !empty($data['sync_products']) ? 1 : 0);
        $this->configWriter->save(Config::XML_PATH_SYNC_CMS_PAGES, !empty($data['sync_cms_pages']) ? 1 : 0);
        $this->configWriter->save(Config::XML_PATH_SYNC_CMS_BLOCKS, !empty($data['sync_cms_blocks']) ? 1 : 0);
        $this->configWriter->save(Config::XML_PATH_SYNC_WIDGETS, !empty($data['sync_widgets']) ? 1 : 0);
        $this->configWriter->save(Config::XML_PATH_SYNC_REVIEWS, !empty($data['sync_reviews']) ? 1 : 0);
        $this->configWriter->save(Config::XML_PATH_SYNC_POLICIES, !empty($data['sync_policies']) ? 1 : 0);
        $this->configWriter->save(Config::XML_PATH_SYNC_FAQ, !empty($data['sync_faq']) ? 1 : 0);
        $this->configWriter->save(Config::XML_PATH_SYNC_STORE_CONFIG, !empty($data['sync_store_config']) ? 1 : 0);
        $this->configWriter->save(Config::XML_PATH_RETAIN_DAYS, max(1, (int) ($data['retain_days'] ?? 30)));
        $this->configWriter->save(Config::XML_PATH_LINK_CUSTOMER_SESSIONS, !empty($data['link_customer_sessions']) ? 1 : 0);

        $this->cacheTypeList->cleanType('config');
    }
}
