<?php

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use kernpfad\commerceklaviyo\services\CatalogItemEnsurer;
use kernpfad\commerceklaviyo\services\CatalogPayloadBuilder;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;

/**
 * Upserts a single catalog variant. The payload is built ahead of time by
 * {@see \kernpfad\commerceklaviyo\services\CatalogSyncService} from a
 * real Variant snapshot — this job only does the network call, so a
 * Klaviyo failure here never affects the variant save that queued it.
 *
 * Queued on the variant's *own* `EVENT_AFTER_SAVE`, not the parent
 * product's — see {@see \kernpfad\commerceklaviyo\services\CatalogSyncService}'s
 * class docblock for why. Also upserts the parent catalog item first via
 * {@see CatalogItemEnsurer}, because the product's own sync job can still
 * be sitting behind this one on the queue when multiple workers are
 * running.
 */
class SyncCatalogVariantJob extends BaseKlaviyoJob
{
    public string $variantExternalId = '';

    public string $productTitle = '';

    /**
     * @var array<string, mixed>
     */
    public array $payload = [];

    public string $parentItemExternalId = '';

    /**
     * @var array<string, mixed>
     */
    public array $parentItemPayload = [];

    /**
     * @var array<int, array{id: string, name: string}>
     */
    public array $parentCategories = [];

    public function execute($queue): void
    {
        $this->withKlaviyoClient(function(KlaviyoClient $client): void {
            if ($this->parentItemExternalId !== '' && $this->parentItemPayload !== []) {
                (new CatalogItemEnsurer($client))->ensure(
                    $this->parentItemExternalId,
                    $this->parentItemPayload,
                    $this->parentCategories,
                );
            }

            $client->upsert(
                'catalog-variants',
                'catalog-variants/' . CatalogPayloadBuilder::compositeId($this->variantExternalId),
                $this->variantExternalId,
                $this->payload,
            );
        });
    }

    protected function skippedMessage(): string
    {
        return 'Commerce Klaviyo: skipped variant sync, no API key configured.';
    }

    protected function errorMessage(\Throwable $e): string
    {
        return "Commerce Klaviyo: failed to sync catalog variant \"{$this->variantExternalId}\": {$e->getMessage()}";
    }

    protected function failureCategory(): ?string
    {
        return KlaviyoStatusService::CATEGORY_CATALOG;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-klaviyo', 'Syncing "{title}" variant to Klaviyo catalog', ['title' => $this->productTitle]);
    }
}
