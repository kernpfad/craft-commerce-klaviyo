<?php

namespace kernpfad\commerceklaviyo\services;

/**
 * Klaviyo Catalogs API bulk create/update jobs for full-catalog reindexes.
 * Real-time single-element saves stay on {@see KlaviyoClient::upsert()};
 * this path is only for {@see CatalogSyncService::reindexAll()} chunk jobs.
 *
 * Create-first, update-retry: bulk create jobs don't upsert, so entries that
 * already exist in Klaviyo (a re-sync, not a first install) land in the
 * job's `errors` array with a duplicate-external-id message — those are
 * retried through the matching bulk *update* endpoint instead.
 */
class BulkCatalogSyncService
{
    public const CHUNK_SIZE = 100;

    public function __construct(
        private readonly KlaviyoClient $client,
    ) {
    }

    /**
     * @param array<int, array{externalId: string, payload: array<string, mixed>}> $entries
     * @return array{jobId: string, status: string, totalCount: int, completedCount: int, failedCount: int}
     */
    public function bulkUpsertItems(array $entries): array
    {
        return $this->bulkUpsert(
            createPath: 'catalog-item-bulk-create-jobs',
            createJobType: 'catalog-item-bulk-create-job',
            createItemsKey: 'items',
            updatePath: 'catalog-item-bulk-update-jobs',
            updateJobType: 'catalog-item-bulk-update-job',
            updateItemsKey: 'items',
            entries: $entries,
        );
    }

    /**
     * @param array<int, array{externalId: string, payload: array<string, mixed>}> $entries
     * @return array{jobId: string, status: string, totalCount: int, completedCount: int, failedCount: int}
     */
    public function bulkUpsertVariants(array $entries): array
    {
        return $this->bulkUpsert(
            createPath: 'catalog-variant-bulk-create-jobs',
            createJobType: 'catalog-variant-bulk-create-job',
            createItemsKey: 'variants',
            updatePath: 'catalog-variant-bulk-update-jobs',
            updateJobType: 'catalog-variant-bulk-update-job',
            updateItemsKey: 'variants',
            entries: $entries,
        );
    }

    /**
     * @param array<int, array{externalId: string, payload: array<string, mixed>}> $entries
     * @return array{jobId: string, status: string, totalCount: int, completedCount: int, failedCount: int}
     */
    private function bulkUpsert(
        string $createPath,
        string $createJobType,
        string $createItemsKey,
        string $updatePath,
        string $updateJobType,
        string $updateItemsKey,
        array $entries,
    ): array {
        if ($entries === []) {
            return [
                'jobId' => '',
                'status' => 'complete',
                'totalCount' => 0,
                'completedCount' => 0,
                'failedCount' => 0,
            ];
        }

        // Each entry's `payload` is a full single-create body from
        // CatalogPayloadBuilder — `{data: {type, attributes}}` — because
        // that's what KlaviyoClient::upsert() needs for the real-time
        // single-element path. The bulk endpoint's `items.data`/
        // `variants.data` array wants the *inner* resource object
        // directly (`{type, attributes}`), not that whole envelope again;
        // sending the envelope nested a second level too deep is why
        // Klaviyo rejected every bulk job with "'type' is a required
        // field for the resource 'request-data'" — the `type` it wanted
        // was one level up from where it actually was.
        $createPayload = [
            'data' => [
                'type' => $createJobType,
                'attributes' => [
                    $createItemsKey => [
                        'data' => array_map(
                            static fn(array $entry): array => $entry['payload']['data'],
                            $entries,
                        ),
                    ],
                ],
            ],
        ];

        $createResponse = $this->client->postReturning($createPath, $createPayload);
        $createJobId = (string)($createResponse['data']['id'] ?? '');
        $createResult = $this->client->pollBulkJob("{$createPath}/{$createJobId}");

        $duplicateExternalIds = $this->extractDuplicateExternalIds($createResult['errors']);

        if ($duplicateExternalIds === []) {
            return $createResult;
        }

        $entriesByExternalId = [];

        foreach ($entries as $entry) {
            $entriesByExternalId[$entry['externalId']] = $entry;
        }

        $updatePayloads = [];

        foreach ($duplicateExternalIds as $externalId) {
            $entry = $entriesByExternalId[$externalId] ?? null;

            if ($entry === null) {
                continue;
            }

            $updatePayloads[] = $this->toBulkUpdatePayload($entry['externalId'], $entry['payload']);
        }

        if ($updatePayloads === []) {
            return $createResult;
        }

        $updateResponse = $this->client->postReturning($updatePath, [
            'data' => [
                'type' => $updateJobType,
                'attributes' => [
                    $updateItemsKey => [
                        'data' => $updatePayloads,
                    ],
                ],
            ],
        ]);
        $updateJobId = (string)($updateResponse['data']['id'] ?? '');
        $updateResult = $this->client->pollBulkJob("{$updatePath}/{$updateJobId}");

        return [
            'jobId' => $updateJobId !== '' ? $updateJobId : $createJobId,
            'status' => $updateResult['status'],
            'totalCount' => $createResult['totalCount'] + $updateResult['totalCount'],
            'completedCount' => $createResult['completedCount'] + $updateResult['completedCount'],
            'failedCount' => $updateResult['failedCount'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     * @return string[]
     */
    public function extractDuplicateExternalIds(array $errors): array
    {
        $externalIds = [];

        foreach ($errors as $error) {
            $externalId = $error['meta']['external_id'] ?? null;
            $detail = (string)($error['detail'] ?? '');

            if (!is_string($externalId) || $externalId === '') {
                continue;
            }

            if (!str_contains(strtolower($detail), 'already exists')) {
                continue;
            }

            $externalIds[] = $externalId;
        }

        return array_values(array_unique($externalIds));
    }

    /**
     * @param array<string, mixed> $createPayload The full `{data: {...}}`
     *   single-create envelope for this entry.
     * @return array<string, mixed> Just the inner resource object
     *   (`{type, attributes, id}`) — same reasoning as the create path
     *   above: the bulk update endpoint's `items.data`/`variants.data`
     *   array wants the resource itself, not another `{data: ...}` wrapper
     *   around it.
     */
    private function toBulkUpdatePayload(string $externalId, array $createPayload): array
    {
        $resource = $createPayload['data'];
        $resource['id'] = CatalogPayloadBuilder::compositeId($externalId);

        unset(
            $resource['attributes']['external_id'],
            $resource['attributes']['integration_type'],
            $resource['attributes']['catalog_type'],
            $resource['relationships'],
        );

        return $resource;
    }
}
