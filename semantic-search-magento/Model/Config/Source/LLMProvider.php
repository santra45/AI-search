<?php
namespace Czar\SemanticSearch\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class LLMProvider implements ArrayInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => __('-- Select LLM Provider --')],
            ['value' => 'gemini', 'label' => __('Google Gemini')],
            ['value' => 'openai', 'label' => __('OpenAI ChatGPT')],
            ['value' => 'anthropic', 'label' => __('Anthropic Claude')],
        ];
    }
}
