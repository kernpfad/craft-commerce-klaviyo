<?php

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use kernpfad\commerceklaviyo\services\CatalogPayloadBuilder;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;

/**
 * Removes a single catalog variant after it's hard-deleted, for when one
 * variant is removed from a product that still exists. Deleting a whole
 * product goes through {@see DeleteCatalogItemJob} instead, which Klaviyo
 * cascades to the item's variants on its own. A soft-delete (trashed,
 * reversible) goes through {@see UnpublishCatalogVariantJob} instead — see
 * {@see \kernpfad\commerceklaviyo\services\CatalogSyncService::deleteVariant()}
 * for the hard-vs-soft branch.
 */
class DeleteCatalogVariantJob extends BaseKlaviyoJob
{
    public string $variantExternalId = '';

    public string $productTitle = '';

    public function execute($queue): void
    {
        $this->withKlaviyoClient(function(KlaviyoClient $client): void {
            $client->delete('catalog-variants/' . CatalogPayloadBuilder::compositeId($this->variantExternalId));
        });
    }

    protected function skippedMessage(): string
    {
        return 'Commerce Klaviyo: skipped catalog variant delete, no API key configured.';
    }

    protected function errorMessage(\Throwable $e): string
    {
        return "Commerce Klaviyo: failed to delete catalog variant \"{$this->variantExternalId}\": {$e->getMessage()}";
    }

    protected function failureCategory(): ?string
    {
        return KlaviyoStatusService::CATEGORY_CATALOG;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-klaviyo', 'Removing a "{title}" variant from Klaviyo catalog', ['title' => $this->productTitle]);
    }
}
