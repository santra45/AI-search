<?php
namespace Czar\SemanticSearch\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;

class Encryption extends \Magento\Framework\App\Helper\AbstractHelper
{
    private EncryptorInterface $encryptor;

    public function __construct(
        Context $context,
        EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
        $this->encryptor = $encryptor;
    }

    /**
     * Encrypt LLM API key using the same method as WordPress
     */
    public function encryptApiKey(string $apiKey, string $licenseKey): string
    {
        if (empty($apiKey) || empty($licenseKey)) {
            return '';
        }

        try {
            // Generate random IV (16 bytes)
            $iv = random_bytes(16);
            
            // Create key from license key using SHA-256
            $key = hash('sha256', $licenseKey, true);

            // Encrypt using AES-256-CBC
            $encrypted = openssl_encrypt(
                $apiKey,
                'AES-256-CBC',
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            // Store as base64: [IV][Encrypted Data]
            return base64_encode($iv . $encrypted);
        } catch (\Exception $e) {
            $this->_logger->error('Failed to encrypt LLM API key: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Decrypt LLM API key
     */
    public function decryptApiKey(string $encryptedApiKey, string $licenseKey): string
    {
        if (empty($encryptedApiKey) || empty($licenseKey)) {
            return '';
        }

        try {
            // Decode base64
            $data = base64_decode($encryptedApiKey);
            if ($data === false) {
                return '';
            }

            // Extract IV (first 16 bytes) and encrypted data
            $iv = substr($data, 0, 16);
            $encrypted = substr($data, 16);

            // Create key from license key using SHA-256
            $key = hash('sha256', $licenseKey, true);

            // Decrypt
            $decrypted = openssl_decrypt(
                $encrypted,
                'AES-256-CBC',
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            return $decrypted !== false ? $decrypted : '';
        } catch (\Exception $e) {
            $this->_logger->error('Failed to decrypt LLM API key: ' . $e->getMessage());
            return '';
        }
    }
}
