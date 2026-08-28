<?php

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use kernpfad\commerceklaviyo\services\CatalogPayloadBuilder;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;

/**
 * Removes a catalog item (and, implicitly, its variants — Klaviyo cascades
 * variant deletion when the parent item is deleted) after a Product is
 * hard-deleted. A soft-delete (trashed, reversible) goes through
 * {@see UnpublishCatalogItemJob} instead — see
 * {@see \kernpfad\commerceklaviyo\services\CatalogSyncService::deleteProduct()}
 * for the hard-vs-soft branch.
 */
class DeleteCatalogItemJob extends BaseKlaviyoJob
{
    public string $itemExternalId = '';

    public string $productTitle = '';

    public function execute($queue): void
    {
        $this->withKlaviyoClient(function(KlaviyoClient $client): void {
            $client->delete('catalog-items/' . CatalogPayloadBuilder::compositeId($this->itemExternalId));
        });
    }

    protected function skippedMessage(): string
    {
        return 'Commerce Klaviyo: skipped catalog delete, no API key configured.';
    }

    protected function errorMessage(\Throwable $e): string
    {
        return "Commerce Klaviyo: failed to delete catalog item \"{$this->itemExternalId}\": {$e->getMessage()}";
    }

    protected function failureCategory(): ?string
    {
        return KlaviyoStatusService::CATEGORY_CATALOG;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-klaviyo', 'Removing "{title}" from Klaviyo catalog', ['title' => $this->productTitle]);
    }
}
