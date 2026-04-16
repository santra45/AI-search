<?php
namespace Czar\AiChatbot\Controller\Adminhtml\Ajax;

use Czar\AiChatbot\Helper\Config;
use Czar\AiChatbot\Model\Admin\SettingsManager;
use Czar\AiChatbot\Model\ApiClient;
use Czar\AiChatbot\Model\Sync\SyncService;
use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\JsonFactory;

class Handle extends Action
{
    public const ADMIN_RESOURCE = 'Czar_AiChatbot::dashboard';

    public function __construct(
        Action\Context $context,
        private JsonFactory $resultJsonFactory,
        private ApiClient $apiClient,
        private SettingsManager $settingsManager,
        private SyncService $syncService,
        private Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $actionName = (string) $this->getRequest()->getParam('action_name', '');

        try {
            $data = match ($actionName) {
                'dashboard_data' => $this->apiClient->getDashboardData(),
                'conversations_data' => $this->apiClient->getConversations(),
                'conversation_history' => $this->apiClient->getHistory(['conversation_id' => (string) $this->getRequest()->getParam('conversation_id', '')]),
                'usage_data' => $this->apiClient->getUsageData((int) $this->getRequest()->getParam('days', 30)),
                'sync_data' => $this->buildSyncPayload(),
                'test_connection' => $this->apiClient->testConnection(),
                'save_settings' => $this->saveSettings(),
                'start_sync' => $this->syncService->startSync(),
                'next_batch' => $this->syncService->processNextBatch(),
                'cancel_sync' => $this->syncService->cancelSync(),
                'reset_sync' => $this->syncService->reset(),
                default => throw new \InvalidArgumentException('Unknown dashboard action.'),
            };

            return $result->setData(['success' => true, 'data' => $data]);
        } catch (\Throwable $exception) {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    private function saveSettings(): array
    {
        $this->settingsManager->save($this->getRequest()->getParams());

        return ['message' => 'Settings saved successfully.'];
    }

    private function buildSyncPayload(): array
    {
        $remote = [];
        try {
            $remote = $this->apiClient->getSyncStatus();
        } catch (\Throwable $exception) {
            $remote = ['message' => $exception->getMessage()];
        }

        return array_merge($remote, [
            'local_state' => $this->syncService->getProgress(),
            'auto_sync_enabled' => $this->config->isAutoSyncEnabled(),
        ]);
    }
}
