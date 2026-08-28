<?php

namespace kernpfad\commerceklaviyo\services;

/**
 * Ensures a parent catalog item exists in Klaviyo before a variant upsert.
 * Shared by {@see \kernpfad\commerceklaviyo\jobs\SyncCatalogVariantJob}
 * (real-time saves, where variant and product jobs can race on the queue).
 */
class CatalogItemEnsurer
{
    public function __construct(
        private readonly KlaviyoClient $client,
    ) {
    }

    /**
     * @param array<string, mixed> $itemPayload
     * @param array<int, array{id: string, name: string}> $categories
     */
    public function ensure(string $itemExternalId, array $itemPayload, array $categories): void
    {
        $this->client->upsert(
            'catalog-items',
            'catalog-items/' . CatalogPayloadBuilder::compositeId($itemExternalId),
            $itemExternalId,
            $itemPayload,
        );

        (new CategorySyncService($this->client))->syncItemCategories($itemExternalId, $categories);
    }
}
