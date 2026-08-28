<?php

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use kernpfad\commerceklaviyo\services\BulkCatalogSyncService;
use kernpfad\commerceklaviyo\services\CategorySyncService;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;

/**
 * Processes up to {@see BulkCatalogSyncService::CHUNK_SIZE} catalog items
 * through Klaviyo's bulk create/update jobs, then syncs each item's category
 * relationships. Used by {@see \kernpfad\commerceklaviyo\services\CatalogSyncService::reindexAll()}
 * instead of enqueueing one {@see SyncCatalogItemJob} per product.
 */
class BulkCatalogItemsChunkJob extends BaseKlaviyoJob
{
    /**
     * @var array<int, array{itemExternalId: string, productTitle: string, itemPayload: array<string, mixed>, categories: array<int, array{id: string, name: string}>}>
     */
    public array $entries = [];

    public function execute($queue): void
    {
        $this->withKlaviyoClient(function(KlaviyoClient $client): void {
            $bulk = new BulkCatalogSyncService($client);
            $bulkEntries = array_map(
                static fn(array $entry): array => [
                    'externalId' => $entry['itemExternalId'],
                    'payload' => $entry['itemPayload'],
                ],
                $this->entries,
            );

            $result = $bulk->bulkUpsertItems($bulkEntries);

            $categorySync = new CategorySyncService($client);

            foreach ($this->entries as $entry) {
                $categorySync->syncItemCategories($entry['itemExternalId'], $entry['categories']);
            }

            (new KlaviyoStatusService())->recordBulkJob([
                'type' => 'items',
                'jobId' => $result['jobId'],
                'status' => $result['status'],
                'totalCount' => $result['totalCount'],
                'completedCount' => $result['completedCount'],
                'failedCount' => $result['failedCount'],
            ]);
        });
    }

    protected function skippedMessage(): string
    {
        return 'Commerce Klaviyo: skipped bulk catalog item sync, no API key configured.';
    }

    protected function errorMessage(\Throwable $e): string
    {
        return 'Commerce Klaviyo: failed to bulk sync catalog items: ' . $e->getMessage();
    }

    protected function failureCategory(): ?string
    {
        return KlaviyoStatusService::CATEGORY_CATALOG;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-klaviyo', 'Bulk syncing {count} catalog item(s) to Klaviyo', [
            'count' => count($this->entries),
        ]);
    }
}
