<?php
namespace Czar\SemanticSearch\Block\Adminhtml;

use Czar\SemanticSearch\Helper\Config;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Dashboard extends Template
{
    protected $_template = 'Czar_SemanticSearch::dashboard.phtml';

    public function __construct(
        Context $context,
        private Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getDashboardDataUrl(): string
    {
        return $this->getUrl('semanticsearch/ajax/dashboardData');
    }

    public function getSyncNowUrl(): string
    {
        return $this->getUrl('semanticsearch/ajax/syncNow');
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getApiUrl(): string
    {
        return $this->config->getApiUrl();
    }
}
