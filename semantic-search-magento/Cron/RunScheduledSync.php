<?php
namespace Czar\SemanticSearch\Cron;

use Czar\SemanticSearch\Helper\Config;
use Czar\SemanticSearch\Model\SyncService;
use Czar\SemanticSearch\Model\SyncStateManager;
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
        if (!$this->config->isAutoSyncEnabled() || !$this->config->isSearchReady()) {
            return;
        }

        if ($this->syncStateManager->isRunning()) {
            return;
        }

        try {
            $this->syncService->startSync();
            $this->syncService->processNextBatch();
        } catch (\Throwable $exception) {
            $this->logger->error('Scheduled semantic search sync failed.', ['message' => $exception->getMessage()]);
        }
    }
}
