<?php
namespace Czar\SemanticSearch\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Czar_SemanticSearch::dashboard';

    public function __construct(
        Action\Context $context,
        private PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Czar_SemanticSearch::dashboard');
        $resultPage->getConfig()->getTitle()->prepend(__('Semantic Search'));

        return $resultPage;
    }
}
