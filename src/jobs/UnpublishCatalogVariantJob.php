<?php

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use kernpfad\commerceklaviyo\services\CatalogPayloadBuilder;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;

/**
 * Unpublishes a catalog variant for a soft-deleted (trashed) variant,
 * rather than removing it outright — see {@see DeleteCatalogVariantJob}
 * for the hard-delete counterpart. Same reasoning as
 * {@see UnpublishCatalogItemJob} for why: a reversible trash shouldn't
 * discard Klaviyo-side history that a restore can't bring back.
 */
class UnpublishCatalogVariantJob extends BaseKlaviyoJob
{
    public string $variantExternalId = '';

    public string $productTitle = '';

    public function execute($queue): void
    {
        $this->withKlaviyoClient(function(KlaviyoClient $client): void {
            $payload = (new CatalogPayloadBuilder())->buildVariantPublishedUpdate($this->variantExternalId, false);

            $client->patch(
                'catalog-variants/' . CatalogPayloadBuilder::compositeId($this->variantExternalId),
                $payload,
            );
        });
    }

    protected function skippedMessage(): string
    {
        return 'Commerce Klaviyo: skipped catalog variant unpublish, no API key configured.';
    }

    protected function errorMessage(\Throwable $e): string
    {
        return "Commerce Klaviyo: failed to unpublish catalog variant \"{$this->variantExternalId}\": {$e->getMessage()}";
    }

    protected function failureCategory(): ?string
    {
        return KlaviyoStatusService::CATEGORY_CATALOG;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-klaviyo', 'Unpublishing a "{title}" variant in Klaviyo catalog (trashed)', ['title' => $this->productTitle]);
    }
}
