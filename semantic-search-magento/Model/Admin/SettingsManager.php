<?php
namespace Czar\SemanticSearch\Model\Admin;

use Czar\SemanticSearch\Helper\Config;
use Czar\SemanticSearch\Helper\Encryption;
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
        $apiUrl = rtrim(trim((string) ($data['api_url'] ?? '')), '/');
        $licenseKey = trim((string) ($data['license_key'] ?? ''));
        $apiKey = trim((string) ($data['api_key'] ?? ''));
        $resultLimit = max(1, min(50, (int) ($data['result_limit'] ?? 10)));
        $enableIntent = !empty($data['enable_intent']) ? 1 : 0;
        $llmProvider = trim((string) ($data['llm_provider'] ?? ''));
        $llmModel = trim((string) ($data['llm_model'] ?? ''));
        $batchSize = max(1, (int) ($data['batch_size'] ?? 20));
        $autoSyncEnabled = !empty($data['auto_sync_enabled']) ? 1 : 0;
        $cronExpression = trim((string) ($data['cron_expression'] ?? '0 * * * *'));
        $existingLlmApiKey = $this->config->getLLMApiKey();
        $submittedLlmApiKey = trim((string) ($data['llm_api_key'] ?? ''));

        if ($apiUrl === '') {
            throw new LocalizedException(__('Endpoint URL is required.'));
        }

        $encryptedLlmApiKey = $existingLlmApiKey;
        if ($submittedLlmApiKey !== '' && $submittedLlmApiKey !== '**************************') {
            $encryptedLlmApiKey = $this->encryption->encryptApiKey($submittedLlmApiKey, $licenseKey);
        }

        $this->configWriter->save(Config::XML_PATH_API_URL, $apiUrl);
        $this->configWriter->save(Config::XML_PATH_API_KEY, $apiKey);
        $this->configWriter->save(Config::XML_PATH_LICENSE_KEY, $licenseKey);
        $this->configWriter->save(Config::XML_PATH_RESULT_LIMIT, $resultLimit);
        $this->configWriter->save(Config::XML_PATH_ENABLE_INTENT, $enableIntent);
        $this->configWriter->save(Config::XML_PATH_LLM_PROVIDER, $llmProvider);
        $this->configWriter->save(Config::XML_PATH_LLM_MODEL, $llmModel);
        $this->configWriter->save(Config::XML_PATH_LLM_API_KEY, $encryptedLlmApiKey);
        $this->configWriter->save(Config::XML_PATH_AUTO_SYNC_ENABLED, $autoSyncEnabled);
        $this->configWriter->save(Config::XML_PATH_AUTO_SYNC_CRON, $cronExpression !== '' ? $cronExpression : '0 * * * *');
        $this->configWriter->save(Config::XML_PATH_BATCH_SIZE, $batchSize);

        $this->cacheTypeList->cleanType('config');
    }
}
