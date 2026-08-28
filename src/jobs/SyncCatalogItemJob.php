<?php

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use kernpfad\commerceklaviyo\services\CatalogPayloadBuilder;
use kernpfad\commerceklaviyo\services\CategorySyncService;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;

/**
 * Upserts a catalog item (the product itself, not its variants — see
 * {@see SyncCatalogVariantJob} for those, queued separately per-variant).
 * The payload is built ahead of time by
 * {@see \kernpfad\commerceklaviyo\services\CatalogSyncService} from a
 * real Product snapshot — this job only does the network call, so a
 * Klaviyo failure here never affects the product save that queued it.
 *
 * Category linking runs right after, in the same job: a category can only
 * be attached to an item that already exists in Klaviyo, so it has to
 * happen after the upsert above succeeds, not queued as a separate job
 * with no guaranteed ordering against this one.
 */
class SyncCatalogItemJob extends BaseKlaviyoJob
{
    public string $itemExternalId = '';

    public string $productTitle = '';

    /**
     * @var array<string, mixed>
     */
    public array $itemPayload = [];

    /**
     * @var array<int, array{id: string, name: string}>
     */
    public array $categories = [];

    public function execute($queue): void
    {
        $this->withKlaviyoClient(function(KlaviyoClient $client): void {
            $client->upsert(
                'catalog-items',
                'catalog-items/' . CatalogPayloadBuilder::compositeId($this->itemExternalId),
                $this->itemExternalId,
                $this->itemPayload,
            );

            (new CategorySyncService($client))->syncItemCategories($this->itemExternalId, $this->categories);
        });
    }

    protected function skippedMessage(): string
    {
        return 'Commerce Klaviyo: skipped catalog sync, no API key configured.';
    }

    protected function errorMessage(\Throwable $e): string
    {
        return "Commerce Klaviyo: failed to sync catalog item \"{$this->itemExternalId}\": {$e->getMessage()}";
    }

    protected function failureCategory(): ?string
    {
        return KlaviyoStatusService::CATEGORY_CATALOG;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-klaviyo', 'Syncing "{title}" to Klaviyo catalog', ['title' => $this->productTitle]);
    }
}
