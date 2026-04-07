<?php
namespace Czar\SemanticSearch\Plugin\Config\Model\Config;

use Czar\SemanticSearch\Helper\Config as SemanticConfig;
use Czar\SemanticSearch\Helper\Encryption;
use Magento\Config\Model\Config;
use Psr\Log\LoggerInterface;

class ConfigPlugin
{
    private SemanticConfig $semanticConfig;
    private Encryption $encryption;
    private LoggerInterface $logger;

    public function __construct(
        SemanticConfig $semanticConfig,
        Encryption $encryption,
        LoggerInterface $logger
    ) {
        $this->semanticConfig = $semanticConfig;
        $this->encryption = $encryption;
        $this->logger = $logger;
    }

    /**
     * Before save configuration, encrypt the LLM API key if provided
     */
    public function beforeSave(Config $subject): void
    {
        $data = $subject->getData();
        
        // Check if this is semantic search configuration
        if (isset($data['groups']['llm_settings']['fields']['llm_api_key']['value'])) {
            $apiKey = $data['groups']['llm_settings']['fields']['llm_api_key']['value'];
            $licenseKey = $data['groups']['general']['fields']['license_key']['value'] ?? '';
            
            // If API key is provided and not already encrypted
            if (!empty($apiKey) && $this->isPlainTextApiKey($apiKey)) {
                try {
                    $encryptedKey = $this->encryption->encryptApiKey($apiKey, $licenseKey);
                    if (!empty($encryptedKey)) {
                        $data['groups']['llm_settings']['fields']['llm_api_key']['value'] = $encryptedKey;
                        $subject->setData($data);
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Failed to encrypt LLM API key: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Check if the API key is plain text (not encrypted)
     */
    private function isPlainTextApiKey(string $apiKey): bool
    {
        // Encrypted keys are base64 strings that contain binary data
        // Plain text keys typically contain readable characters only
        $decoded = base64_decode($apiKey, true);
        
        // If it's valid base64 and contains non-printable characters, it's likely encrypted
        if ($decoded !== false && strlen($decoded) > 16) {
            // Check if it contains IV (first 16 bytes) + encrypted data
            $iv = substr($decoded, 0, 16);
            $encrypted = substr($decoded, 16);
            
            // If both parts exist and IV contains binary data, it's encrypted
            if (strlen($iv) === 16 && strlen($encrypted) > 0) {
                return false; // Already encrypted
            }
        }
        
        return true; // Plain text
    }
}
