<?php
namespace Czar\SemanticSearch\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class LLMModel implements ArrayInterface
{
    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    public function toOptionArray(): array
    {
        $provider = $this->scopeConfig->getValue(
            'semantic_search/llm_settings/llm_provider',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $options = [['value' => '', 'label' => __('-- Select Provider First --')]];

        switch ($provider) {
        case 'gemini':
            $options = array_merge($options, [

                ['value' => 'gemini-3.1-pro-preview', 'label' => __('Gemini 3.1 Pro (Latest)')],
                ['value' => 'gemini-2.5-pro', 'label' => __('Gemini 2.5 Pro (Stable)')],
                ['value' => 'gemini-2.5-flash', 'label' => __('Gemini 2.5 Flash (Fast)')],
                ['value' => 'gemini-2.5-flash-lite', 'label' => __('Gemini 2.5 Flash Lite (Budget)')],
                ['value' => 'gemma-3-27b-it', 'label' => __('Gemma 3 27B (Free)')],
            ]);
            break;
        case 'openai':
            $options = array_merge($options, [

                ['value' => 'gpt-5.4', 'label' => __('GPT-5.4 (Latest)')],
                ['value' => 'gpt-5.4-mini', 'label' => __('GPT-5.4 Mini (Fast)')],
                ['value' => 'gpt-5.4-nano', 'label' => __('GPT-5.4 Nano (Budget)')],
                ['value' => 'gpt-5.2', 'label' => __('GPT-5.2 (Previous Gen)')],
            ]);
            break;
        case 'anthropic':
            $options = array_merge($options, [
                ['value' => 'claude-opus-4-6', 'label' => __('Claude Opus 4.6 (Most Powerful)')],
                ['value' => 'claude-sonnet-4-6', 'label' => __('Claude Sonnet 4.6 (Balanced)')],
                ['value' => 'claude-haiku-4-5-20251001', 'label' => __('Claude Haiku 4.5 (Fast)')],
                ['value' => 'claude-3-5-sonnet-20241022', 'label' => __('Claude 3.5 Sonnet (Legacy)')],
            ]);
            break;
        }
        return $options;
    }
}
