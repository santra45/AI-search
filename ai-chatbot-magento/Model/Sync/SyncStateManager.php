<?php
namespace Czar\AiChatbot\Model\Sync;

use Magento\Framework\FlagManager;

class SyncStateManager
{
    private const FLAG_CODE = 'ai_chatbot_sync_state';

    public function __construct(private FlagManager $flagManager)
    {
    }

    public function getState(): array
    {
        $raw = $this->flagManager->getFlagData(self::FLAG_CODE);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? array_replace($this->getDefaultState(), $decoded) : $this->getDefaultState();
    }

    public function start(array $manifest, array $contentSequence, array $skippedTypes, int $batchSize, int $storeId): array
    {
        $total = array_sum($manifest);
        $totalBatches = $batchSize > 0 ? (int) ceil($total / $batchSize) : 0;
        $state = $this->getDefaultState();
        $state['status'] = $total > 0 ? 'running' : 'empty';
        $state['manifest'] = $manifest;
        $state['content_sequence'] = $contentSequence;
        $state['skipped_types'] = $skippedTypes;
        $state['total'] = $total;
        $state['total_batches'] = $totalBatches;
        $state['batch_size'] = $batchSize;
        $state['store_id'] = $storeId;
        $state['current_type'] = $contentSequence[0] ?? '';
        $state['current_page'] = 1;
        $state['started_at'] = gmdate('c');
        $state['finished_at'] = null;
        $state['last_error'] = '';

        $this->saveState($state);

        return $state;
    }

    public function update(array $changes): array
    {
        $state = array_replace($this->getState(), $changes);
        $state['percentage'] = $this->calculatePercentage($state);
        $this->saveState($state);

        return $state;
    }

    public function markComplete(): array
    {
        return $this->update([
            'status' => 'complete',
            'finished_at' => gmdate('c'),
            'last_synced_at' => gmdate('c'),
            'current_batch' => $this->getState()['total_batches'],
        ]);
    }

    public function markCancelled(): array
    {
        return $this->update([
            'status' => 'cancelled',
            'finished_at' => gmdate('c'),
        ]);
    }

    public function markError(string $message): array
    {
        return $this->update([
            'status' => 'error',
            'finished_at' => gmdate('c'),
            'last_error' => $message,
        ]);
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
            'manifest' => [],
            'content_sequence' => [],
            'skipped_types' => [],
            'total' => 0,
            'processed' => 0,
            'failed' => 0,
            'total_batches' => 0,
            'current_batch' => 0,
            'batch_size' => 0,
            'store_id' => 0,
            'current_type' => '',
            'current_page' => 1,
            'started_at' => null,
            'finished_at' => null,
            'last_synced_at' => null,
            'last_error' => '',
            'percentage' => 0,
        ];
    }
}
