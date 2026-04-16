<?php
namespace Czar\AiChatbot\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;

class Encryption extends AbstractHelper
{
    public function __construct(
        Context $context,
        private EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
    }

    public function encryptApiKey(string $apiKey, string $licenseKey): string
    {
        if ($apiKey === '' || $licenseKey === '') {
            return '';
        }

        try {
            $iv = random_bytes(16);
            $key = hash('sha256', $licenseKey, true);
            $encrypted = openssl_encrypt($apiKey, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

            return $encrypted ? base64_encode($iv . $encrypted) : '';
        } catch (\Throwable $exception) {
            $this->_logger->error('Failed to encrypt chatbot LLM API key.', ['message' => $exception->getMessage()]);

            return '';
        }
    }
}
