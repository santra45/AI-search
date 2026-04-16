<?php
namespace Czar\AiChatbot\Cron;

use Czar\AiChatbot\Model\Sync\SyncService;
use Czar\AiChatbot\Model\Sync\SyncStateManager;

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
