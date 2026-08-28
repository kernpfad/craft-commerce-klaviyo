<?php

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use kernpfad\commerceklaviyo\services\CatalogPayloadBuilder;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;

/**
 * Unpublishes a catalog item for a soft-deleted (trashed) product, rather
 * than removing it outright — see {@see DeleteCatalogItemJob} for the
 * hard-delete counterpart, and
 * {@see \kernpfad\commerceklaviyo\services\CatalogSyncService::deleteProduct()}
 * for which one a given delete actually triggers.
 *
 * A routine "move to trash" is reversible in Craft; discarding the catalog
 * item's Klaviyo-side identity (and engagement history) on every trash
 * click would make it irreversible on the Klaviyo side even though nothing
 * was actually lost in Craft. Unpublishing keeps the resource around,
 * inactive, so restoring the product doesn't mean starting over as a new
 * catalog entry.
 */
class UnpublishCatalogItemJob extends BaseKlaviyoJob
{
    public string $itemExternalId = '';

    public string $productTitle = '';

    public function execute($queue): void
    {
        $this->withKlaviyoClient(function(KlaviyoClient $client): void {
            $payload = (new CatalogPayloadBuilder())->buildItemPublishedUpdate($this->itemExternalId, false);

            $client->patch(
                'catalog-items/' . CatalogPayloadBuilder::compositeId($this->itemExternalId),
                $payload,
            );
        });
    }

    protected function skippedMessage(): string
    {
        return 'Commerce Klaviyo: skipped catalog unpublish, no API key configured.';
    }

    protected function errorMessage(\Throwable $e): string
    {
        return "Commerce Klaviyo: failed to unpublish catalog item \"{$this->itemExternalId}\": {$e->getMessage()}";
    }

    protected function failureCategory(): ?string
    {
        return KlaviyoStatusService::CATEGORY_CATALOG;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-klaviyo', 'Unpublishing "{title}" in Klaviyo catalog (trashed)', ['title' => $this->productTitle]);
    }
}
