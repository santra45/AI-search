<?php
namespace Czar\SemanticSearch\Cron;

use Czar\SemanticSearch\Model\SyncService;
use Czar\SemanticSearch\Model\SyncStateManager;

class ProcessSyncQueue
{
    public function __construct(
        private SyncStateManager $syncStateManager,
        private SyncService $syncService
    ) {
    }

    public function execute(): void
    {
        if (!$this->syncStateManager->isRunning()) {
            return;
        }

        $this->syncService->processNextBatch();
    }
}
