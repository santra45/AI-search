<?php
namespace Czar\SemanticSearch\Controller\Adminhtml\Ajax;

use Czar\SemanticSearch\Model\SyncService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class SyncNow extends Action
{
    public const ADMIN_RESOURCE = 'Czar_SemanticSearch::dashboard';

    public function __construct(
        Context $context,
        private JsonFactory $resultJsonFactory,
        private SyncService $syncService
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $batchSize = (int) $this->getRequest()->getParam('batch_size', 50);
        $data = $this->syncService->syncAll(max($batchSize, 1));
        return $result->setData($data);
    }
}
