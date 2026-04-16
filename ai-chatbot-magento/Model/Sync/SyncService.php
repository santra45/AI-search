<?php
namespace Czar\AiChatbot\Model\Sync;

use Czar\AiChatbot\Helper\Config;
use Czar\AiChatbot\Model\ApiClient;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class SyncService
{
    public function __construct(
        private Config $config,
        private ContentBatchAssembler $contentBatchAssembler,
        private ApiClient $apiClient,
        private SyncStateManager $syncStateManager,
        private ProductSyncInspector $productSyncInspector,
        private LoggerInterface $logger
    ) {
    }

    public function startSync(?int $storeId = null): array
    {
        $resolvedStoreId = $storeId ?? 0;
        if (!$this->config->isChatReady($resolvedStoreId)) {
            throw new LocalizedException(__('AI Chatbot is not configured.'));
        }

        $manifest = $this->contentBatchAssembler->getManifest($resolvedStoreId);
        $skippedTypes = [];
        if (($manifest['product'] ?? 0) > 0 && $this->productSyncInspector->productsReady($resolvedStoreId)) {
            unset($manifest['product']);
            $skippedTypes[] = 'product';
        }

        $contentSequence = array_values(array_keys(array_filter($manifest, static fn($count): bool => (int) $count > 0)));
        if ($contentSequence === []) {
            $this->syncStateManager->reset();

            return $this->syncStateManager->update([
                'status' => 'complete',
                'manifest' => $manifest,
                'skipped_types' => $skippedTypes,
                'last_synced_at' => gmdate('c'),
            ]);
        }

        return $this->syncStateManager->start(
            $manifest,
            $contentSequence,
            $skippedTypes,
            $this->config->getBatchSize($resolvedStoreId),
            $resolvedStoreId
        );
    }

    public function processNextBatch(): array
    {
        $state = $this->syncStateManager->getState();
        if ($state['status'] !== 'running') {
            return $state;
        }

        $sequence = $state['content_sequence'];
        $currentType = $state['current_type'] ?: ($sequence[0] ?? '');
        $currentPage = max(1, (int) $state['current_page']);
        $batchSize = (int) $state['batch_size'];
        $storeId = (int) $state['store_id'];

        if ($currentType === '') {
            return $this->syncStateManager->markComplete();
        }

        try {
            $items = $this->contentBatchAssembler->getBatch($currentType, $currentPage, $batchSize, $storeId);
            if ($items === []) {
                return $this->advanceToNextType($state, $currentType);
            }

            $nextBatchNumber = (int) $state['current_batch'] + 1;
            $result = $this->apiClient->syncBatch($items, $nextBatchNumber, max(1, (int) $state['total_batches']), $storeId);
            $processed = (int) $state['processed'] + (int) ($result['success_count'] ?? 0);
            $failed = (int) $state['failed'] + (int) ($result['failed_count'] ?? 0);

            $nextState = $this->syncStateManager->update([
                'current_batch' => $nextBatchNumber,
                'processed' => $processed,
                'failed' => $failed,
                'current_page' => $currentPage + 1,
                'current_type' => $currentType,
            ]);

            if ($nextState['current_batch'] >= $nextState['total_batches']) {
                return $this->syncStateManager->markComplete();
            }

            return $nextState;
        } catch (\Throwable $exception) {
            $this->logger->error('AI Chatbot sync batch failed.', ['message' => $exception->getMessage()]);

            return $this->syncStateManager->markError($exception->getMessage());
        }
    }

    public function syncEntity(string $contentType, int $entityId, ?int $storeId = null): array
    {
        if (!$this->config->isChatReady($storeId)) {
            return ['status' => 'disabled'];
        }

        $item = $this->contentBatchAssembler->getSingleItem($contentType, $entityId, (int) ($storeId ?? 0));
        if ($item === null) {
            return ['status' => 'skipped'];
        }

        return $this->apiClient->syncBatch([$item], 1, 1, $storeId);
    }

    public function deleteEntity(string $contentType, int $entityId, ?int $storeId = null): array
    {
        if (!$this->config->isChatReady($storeId)) {
            return ['status' => 'disabled'];
        }

        return $this->apiClient->deleteItems([[
            'entity_id' => (string) $entityId,
            'content_type' => $contentType,
        ]], $storeId);
    }

    public function getProgress(): array
    {
        return $this->syncStateManager->getState();
    }

    public function cancelSync(): array
    {
        return $this->syncStateManager->markCancelled();
    }

    public function reset(): array
    {
        return $this->syncStateManager->reset();
    }

    private function advanceToNextType(array $state, string $currentType): array
    {
        $sequence = array_values($state['content_sequence']);
        $index = array_search($currentType, $sequence, true);
        $nextType = $index === false ? null : ($sequence[$index + 1] ?? null);
        if ($nextType === null) {
            return $this->syncStateManager->markComplete();
        }

        return $this->syncStateManager->update([
            'current_type' => $nextType,
            'current_page' => 1,
        ]);
    }
}
