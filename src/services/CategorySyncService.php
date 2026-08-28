<?php

namespace kernpfad\commerceklaviyo\services;

use GuzzleHttp\Exception\ClientException;

/**
 * Ensures a catalog item's Klaviyo `catalog-category` resources exist and
 * are linked to it, given the Craft `Category` elements resolved off a
 * product (see {@see CatalogFieldResolver::resolveCategories()}). Called
 * from {@see \kernpfad\commerceklaviyo\jobs\SyncCatalogItemJob} right after
 * the item itself is upserted — a category can't be attached to an item
 * that doesn't exist in Klaviyo yet.
 *
 * Sync is replace-aware: categories removed from a product in Craft are
 * unlinked in Klaviyo too, by diffing the item's current Klaviyo
 * relationships against the desired Craft category set.
 */
class CategorySyncService
{
    public function __construct(
        private readonly KlaviyoClient $client,
        private readonly CatalogPayloadBuilder $payloadBuilder = new CatalogPayloadBuilder(),
    ) {
    }

    /**
     * @param array<int, array{id: string, name: string}> $categories As
     *   returned by {@see CatalogFieldResolver::resolveCategories()}.
     */
    public function syncItemCategories(string $itemExternalId, array $categories): void
    {
        $desiredExternalIds = [];

        foreach ($categories as $category) {
            $this->upsertCategory($category['id'], $category['name']);
            $desiredExternalIds[] = $category['id'];
        }

        $linkedExternalIds = $this->fetchLinkedCategoryExternalIds($itemExternalId);

        $toRemove = array_values(array_diff($linkedExternalIds, $desiredExternalIds));
        $toAdd = array_values(array_diff($desiredExternalIds, $linkedExternalIds));

        if ($toRemove !== []) {
            $this->detachFromItem($itemExternalId, $toRemove);
        }

        if ($toAdd !== []) {
            $this->attachToItem($itemExternalId, $toAdd);
        }
    }

    /**
     * @return string[]
     */
    public function fetchLinkedCategoryExternalIds(string $itemExternalId): array
    {
        try {
            $response = $this->client->get(
                'catalog-items/' . CatalogPayloadBuilder::compositeId($itemExternalId) . '/relationships/categories',
            );
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return [];
            }

            throw $e;
        }

        $externalIds = [];

        foreach ($response['data'] ?? [] as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $compositeId = (string)($relationship['id'] ?? '');

            if ($compositeId === '') {
                continue;
            }

            $externalIds[] = self::externalIdFromCompositeId($compositeId);
        }

        return $externalIds;
    }

    public static function externalIdFromCompositeId(string $compositeId): string
    {
        $parts = explode(':::', $compositeId);

        return (string)(end($parts) ?: $compositeId);
    }

    private function upsertCategory(string $externalId, string $name): void
    {
        $payload = $this->payloadBuilder->buildCategory($externalId, $name);

        $this->client->upsert(
            'catalog-categories',
            'catalog-categories/' . CatalogPayloadBuilder::compositeId($externalId),
            $externalId,
            $payload,
        );
    }

    /**
     * @param string[] $categoryExternalIds
     */
    private function attachToItem(string $itemExternalId, array $categoryExternalIds): void
    {
        $payload = $this->payloadBuilder->buildCategoryRelationships($categoryExternalIds);

        $this->client->addRelationship(
            'catalog-items/' . CatalogPayloadBuilder::compositeId($itemExternalId) . '/relationships/categories',
            $payload,
        );
    }

    /**
     * @param string[] $categoryExternalIds
     */
    private function detachFromItem(string $itemExternalId, array $categoryExternalIds): void
    {
        $payload = $this->payloadBuilder->buildCategoryRelationships($categoryExternalIds);

        $this->client->removeRelationship(
            'catalog-items/' . CatalogPayloadBuilder::compositeId($itemExternalId) . '/relationships/categories',
            $payload,
        );
    }
}
