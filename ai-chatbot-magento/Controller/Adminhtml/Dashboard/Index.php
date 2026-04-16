<?php
namespace Czar\AiChatbot\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Czar_AiChatbot::dashboard';

    public function __construct(Action\Context $context, private PageFactory $resultPageFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Czar_AiChatbot::dashboard');
        $page->getConfig()->getTitle()->prepend(__('AI Chatbot'));

        return $page;
    }
}
