<?php

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use kernpfad\commerceklaviyo\services\BulkCatalogSyncService;
use kernpfad\commerceklaviyo\services\CatalogItemEnsurer;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;

/**
 * Processes up to {@see BulkCatalogSyncService::CHUNK_SIZE} catalog variants
 * through Klaviyo's bulk create/update jobs. Each entry carries its parent
 * item payload so {@see CatalogItemEnsurer} can upsert the parent first —
 * bulk item chunks and variant chunks can still run out of order on a
 * multi-worker queue.
 */
class BulkCatalogVariantsChunkJob extends BaseKlaviyoJob
{
    /**
     * @var array<int, array{
     *   variantExternalId: string,
     *   productTitle: string,
     *   payload: array<string, mixed>,
     *   parentItemExternalId: string,
     *   parentItemPayload: array<string, mixed>,
     *   parentCategories: array<int, array{id: string, name: string}>
     * }>
     */
    public array $entries = [];

    public function execute($queue): void
    {
        $this->withKlaviyoClient(function(KlaviyoClient $client): void {
            $ensurer = new CatalogItemEnsurer($client);
            $parentIds = [];

            foreach ($this->entries as $entry) {
                $parentId = $entry['parentItemExternalId'];

                if (isset($parentIds[$parentId])) {
                    continue;
                }

                $ensurer->ensure(
                    $parentId,
                    $entry['parentItemPayload'],
                    $entry['parentCategories'],
                );
                $parentIds[$parentId] = true;
            }

            $bulk = new BulkCatalogSyncService($client);
            $bulkEntries = array_map(
                static fn(array $entry): array => [
                    'externalId' => $entry['variantExternalId'],
                    'payload' => $entry['payload'],
                ],
                $this->entries,
            );

            $result = $bulk->bulkUpsertVariants($bulkEntries);

            (new KlaviyoStatusService())->recordBulkJob([
                'type' => 'variants',
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
        return 'Commerce Klaviyo: skipped bulk catalog variant sync, no API key configured.';
    }

    protected function errorMessage(\Throwable $e): string
    {
        return 'Commerce Klaviyo: failed to bulk sync catalog variants: ' . $e->getMessage();
    }

    protected function failureCategory(): ?string
    {
        return KlaviyoStatusService::CATEGORY_CATALOG;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-klaviyo', 'Bulk syncing {count} catalog variant(s) to Klaviyo', [
            'count' => count($this->entries),
        ]);
    }
}
