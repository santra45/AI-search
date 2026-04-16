<?php
namespace Czar\AiChatbot\Cron;

use Czar\AiChatbot\Helper\Config;
use Czar\AiChatbot\Model\Sync\SyncService;
use Czar\AiChatbot\Model\Sync\SyncStateManager;
use Psr\Log\LoggerInterface;

class RunScheduledSync
{
    public function __construct(
        private Config $config,
        private SyncStateManager $syncStateManager,
        private SyncService $syncService,
        private LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isAutoSyncEnabled() || !$this->config->isChatReady()) {
            return;
        }

        if ($this->syncStateManager->isRunning()) {
            return;
        }

        try {
            $this->syncService->startSync();
            $this->syncService->processNextBatch();
        } catch (\Throwable $exception) {
            $this->logger->error('Scheduled AI Chatbot sync failed.', ['message' => $exception->getMessage()]);
        }
    }
}
