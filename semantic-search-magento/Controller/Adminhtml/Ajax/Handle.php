<?php
namespace Czar\SemanticSearch\Controller\Adminhtml\Ajax;

use Czar\SemanticSearch\Helper\Config;
use Czar\SemanticSearch\Model\Admin\SettingsManager;
use Czar\SemanticSearch\Model\ApiClient;
use Czar\SemanticSearch\Model\SyncService;
use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\JsonFactory;

class Handle extends Action
{
    public const ADMIN_RESOURCE = 'Czar_SemanticSearch::dashboard';

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
                'dashboard_data' => $this->apiClient->getDashboardStats(),
                'analytics_data' => $this->apiClient->getAnalyticsData((int) $this->getRequest()->getParam('days', 7)),
                'usage_data' => $this->apiClient->getUsageData(),
                'status_data' => $this->buildStatusPayload(),
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
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function saveSettings(): array
    {
        $this->settingsManager->save($this->getRequest()->getParams());

        return ['message' => 'Settings saved successfully.'];
    }

    private function buildStatusPayload(): array
    {
        $syncState = $this->syncService->getProgress();

        try {
            $remote = $this->apiClient->getStatus();

            return array_merge($remote, [
                'api_reachable' => true,
                'license_valid' => true,
                'search_active' => $this->config->isSearchReady(),
                'automatic_sync_ok' => true,
                'sync_status' => $syncState['status'],
                'sync_progress' => $syncState['percentage'],
                'last_error' => $syncState['last_error'],
                'cron_enabled' => $this->config->isAutoSyncEnabled(),
            ]);
        } catch (\Throwable $exception) {
            return [
                'api_reachable' => false,
                'license_valid' => false,
                'client_name' => '',
                'plan' => '',
                'domain' => '',
                'indexed_count' => 0,
                'search_active' => $this->config->isSearchReady(),
                'automatic_sync_ok' => true,
                'sync_status' => $syncState['status'],
                'sync_progress' => $syncState['percentage'],
                'last_error' => $syncState['last_error'] ?: $exception->getMessage(),
                'cron_enabled' => $this->config->isAutoSyncEnabled(),
            ];
        }
    }
}
