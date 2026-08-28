<?php

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\events\BuildCatalogInventoryPayloadEvent;
use kernpfad\commerceklaviyo\services\CatalogPayloadBuilder;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;
use kernpfad\commerceklaviyo\services\PayloadEventDispatcher;

/**
 * A lightweight inventory-only PATCH, queued whenever Commerce executes an
 * inventory movement (restocks, sales, manual adjustments — anything that
 * changes `Purchasable::getStock()`). Deliberately doesn't re-sync the
 * whole product: back-in-stock detection only needs an accurate, timely
 * `inventory_quantity` on the existing catalog variant. Klaviyo's own
 * back-in-stock flow handles noticing the 0 -> positive transition; this
 * job's only job is to keep the number honest.
 */
class SyncVariantInventoryJob extends BaseKlaviyoJob
{
    public string $variantExternalId = '';

    public int $inventoryQuantity = 0;

    public bool $published = true;

    public function execute($queue): void
    {
        $this->withKlaviyoClient(function(KlaviyoClient $client): void {
            $payload = (new CatalogPayloadBuilder())->buildInventoryUpdate($this->variantExternalId, $this->inventoryQuantity, $this->published);

            $payload = (new PayloadEventDispatcher())->dispatch(
                CommerceKlaviyo::EVENT_BEFORE_BUILD_CATALOG_INVENTORY_PAYLOAD,
                new BuildCatalogInventoryPayloadEvent(
                    $this->variantExternalId,
                    $this->inventoryQuantity,
                    $this->published,
                    $payload,
                ),
            );

            $client->patch(
                'catalog-variants/' . CatalogPayloadBuilder::compositeId($this->variantExternalId),
                $payload,
            );
        });
    }

    protected function skippedMessage(): string
    {
        return 'Commerce Klaviyo: skipped inventory sync, no API key configured.';
    }

    protected function errorMessage(\Throwable $e): string
    {
        return "Commerce Klaviyo: failed to sync inventory for variant \"{$this->variantExternalId}\": {$e->getMessage()}";
    }

    protected function failureCategory(): ?string
    {
        return KlaviyoStatusService::CATEGORY_CATALOG;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-klaviyo', 'Syncing inventory to Klaviyo');
    }
}
