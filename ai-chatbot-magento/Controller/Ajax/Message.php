<?php
namespace Czar\AiChatbot\Controller\Ajax;

use Czar\AiChatbot\Model\Chat\ChatHistoryRepository;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

class Message extends Action
{
    public function __construct(
        Context $context,
        private JsonFactory $resultJsonFactory,
        private ChatHistoryRepository $chatHistoryRepository,
        private StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $message = trim((string) $this->getRequest()->getParam('message', ''));
        if ($message === '') {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => __('Message cannot be empty.')]);
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();

            return $result->setData(['success' => true, 'data' => $this->chatHistoryRepository->sendMessage($message, $storeId)]);
        } catch (\Throwable $exception) {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => $exception->getMessage()]);
        }
    }
}
