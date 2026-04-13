<?php
namespace Czar\SemanticSearch\Model;

use Magento\Framework\FlagManager;

class SyncStateManager
{
    private const FLAG_CODE = 'semantic_search_sync_state';

    public function __construct(private FlagManager $flagManager)
    {
    }

    public function getState(): array
    {
        $raw = $this->flagManager->getFlagData(self::FLAG_CODE);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            return $this->getDefaultState();
        }

        return array_replace($this->getDefaultState(), $decoded);
    }

    public function start(int $total, int $totalBatches, int $batchSize, int $storeId): array
    {
        $state = $this->getDefaultState();
        $state['status'] = 'running';
        $state['total'] = $total;
        $state['total_batches'] = $totalBatches;
        $state['batch_size'] = $batchSize;
        $state['store_id'] = $storeId;
        $state['started_at'] = gmdate('c');
        $state['finished_at'] = null;
        $state['last_error'] = '';

        $this->saveState($state);

        return $state;
    }

    public function updateProgress(array $changes): array
    {
        $state = array_replace($this->getState(), $changes);
        $this->saveState($state);

        return $state;
    }

    public function markComplete(): array
    {
        $state = $this->getState();
        $state['status'] = 'complete';
        $state['finished_at'] = gmdate('c');
        $state['last_synced_at'] = gmdate('c');
        $state['current_batch'] = $state['total_batches'];
        $this->saveState($state);

        return $state;
    }

    public function markCancelled(): array
    {
        $state = $this->getState();
        $state['status'] = 'cancelled';
        $state['finished_at'] = gmdate('c');
        $this->saveState($state);

        return $state;
    }

    public function markError(string $message): array
    {
        $state = $this->getState();
        $state['status'] = 'error';
        $state['last_error'] = $message;
        $state['finished_at'] = gmdate('c');
        $this->saveState($state);

        return $state;
    }

    public function reset(): array
    {
        $current = $this->getState();
        $state = $this->getDefaultState();
        $state['last_synced_at'] = $current['last_synced_at'];
        $this->saveState($state);

        return $state;
    }

    public function isRunning(): bool
    {
        return $this->getState()['status'] === 'running';
    }

    public function getProgressSummary(): array
    {
        $state = $this->getState();
        $state['percentage'] = $this->calculatePercentage($state);

        return $state;
    }

    private function saveState(array $state): void
    {
        $this->flagManager->saveFlag(self::FLAG_CODE, json_encode($state));
    }

    private function calculatePercentage(array $state): int
    {
        if ((int) $state['total'] <= 0) {
            return 0;
        }

        return min((int) round(((int) $state['processed'] / (int) $state['total']) * 100), 100);
    }

    private function getDefaultState(): array
    {
        return [
            'status' => 'idle',
            'total' => 0,
            'processed' => 0,
            'failed' => 0,
            'total_batches' => 0,
            'current_batch' => 0,
            'batch_size' => 0,
            'store_id' => 0,
            'started_at' => null,
            'finished_at' => null,
            'last_synced_at' => null,
            'last_error' => '',
            'percentage' => 0,
        ];
    }
}
