<?php

namespace kernpfad\commerceklaviyo\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\helpers\Queue;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\events\BuildCatalogItemPayloadEvent;
use kernpfad\commerceklaviyo\events\BuildCatalogVariantPayloadEvent;
use kernpfad\commerceklaviyo\jobs\BulkCatalogItemsChunkJob;
use kernpfad\commerceklaviyo\jobs\BulkCatalogVariantsChunkJob;
use kernpfad\commerceklaviyo\jobs\DeleteCatalogItemJob;
use kernpfad\commerceklaviyo\jobs\DeleteCatalogVariantJob;
use kernpfad\commerceklaviyo\jobs\SyncCatalogItemJob;
use kernpfad\commerceklaviyo\jobs\SyncCatalogVariantJob;
use kernpfad\commerceklaviyo\jobs\SyncVariantInventoryJob;
use kernpfad\commerceklaviyo\jobs\UnpublishCatalogItemJob;
use kernpfad\commerceklaviyo\jobs\UnpublishCatalogVariantJob;
use yii\base\Component;
use yii\queue\Queue as YiiQueue;

/**
 * Builds Klaviyo catalog payloads from real Product/Variant data and
 * queues the sync. External IDs are the Craft element IDs themselves
 * (stable, already unique, no separate ID-mapping table needed).
 *
 * Variants sync on the variant's *own* `EVENT_AFTER_SAVE`, not the parent
 * product's — verified against a real save, not assumed: at the moment a
 * Product's own `EVENT_AFTER_SAVE` fires, its variants haven't been
 * persisted yet (`$variant->id` is still null, and a fresh DB query for
 * them returns nothing), because Commerce saves a product's variants as
 * their own, separate element saves *after* the product's own save
 * completes. Listening at the product level (this service's original
 * implementation) silently skipped every variant on every real save,
 * meaning no catalog-variant ever actually reached Klaviyo — caught while
 * building the commerce-doofinder plugin, which has the identical shape of
 * bug on the identical Commerce lifecycle fact, and fixed here the same
 * way: sync each variant on its own `EVENT_AFTER_SAVE`, where its id and
 * `getProduct()` are both reliably available.
 *
 * `title` defaults to the product's (or variant's) own native title;
 * `description` and `image_full_url` aren't in Commerce's core schema at
 * all — they're always project-specific custom fields. Configure
 * {@see \kernpfad\commerceklaviyo\models\Settings::$titleFieldHandle},
 * {@see \kernpfad\commerceklaviyo\models\Settings::$descriptionFieldHandle},
 * and {@see \kernpfad\commerceklaviyo\models\Settings::$imageFieldHandle}
 * to map them to a custom field instead; otherwise description falls back
 * to the (possibly also overridden) title, and the image is omitted.
 *
 * Sync jobs are pushed to whichever queue component `$queue` is
 * ({@see \kernpfad\commerceklaviyo\models\Settings::$queueComponentId}),
 * or Craft's own default when null.
 *
 * Drafts, revisions, and cross-site propagation saves are all skipped — see
 * {@see self::isSyncable()}.
 */
class CatalogSyncService extends Component
{
    /**
     * Stand-in inventory for variants that do not track stock. Klaviyo
     * requires a quantity; a high value keeps untracked items available
     * in product feeds and back-in-stock logic.
     */
    private const UNTRACKED_INVENTORY_QUANTITY = 999999;

    /**
     * Guards {@see reindexAll()} against two runs (e.g. a console reindex
     * and a CP "Sync now" click) enqueueing the same catalog twice
     * concurrently.
     */
    private const REINDEX_MUTEX_NAME = 'commerce-klaviyo-reindex';
    private const REINDEX_MUTEX_TIMEOUT = 5;

    /**
     * Craft's default queue job `ttr` (time-to-reserve, ~300s) is too
     * short for a bulk chunk job: {@see KlaviyoClient::pollBulkJob()}
     * alone can legitimately run for up to 600s, and a chunk job calls it
     * up to twice (create, then update-on-duplicate) plus per-item
     * category syncing after. Verified live: the default `ttr` killed a
     * bulk item chunk job mid-poll with "exceeded the timeout of 300
     * seconds" even though the underlying Klaviyo job was processing
     * completely normally, just slower than 5 minutes for 6 items.
     */
    private const BULK_JOB_TTR_SECONDS = 1500;

    public function __construct(
        private readonly CatalogPayloadBuilder $payloadBuilder = new CatalogPayloadBuilder(),
        private readonly CatalogFieldResolver $fieldResolver = new CatalogFieldResolver(),
        private readonly PayloadEventDispatcher $payloadEvents = new PayloadEventDispatcher(),
        private readonly ProfileMapper $fieldMapper = new ProfileMapper(),
        private readonly ?string $titleFieldHandle = null,
        private readonly ?string $descriptionFieldHandle = null,
        private readonly ?string $imageFieldHandle = null,
        private readonly ?string $categoriesFieldHandle = null,
        /** @var array<string, string> craftFieldHandle => klaviyoMetadataKey */
        private readonly array $catalogFieldMapping = [],
        /**
         * See {@see \kernpfad\commerceklaviyo\models\Settings::$inventoryReportingThreshold}.
         * Null / 0 = always report real stock for tracked variants.
         */
        private readonly ?int $inventoryReportingThreshold = null,
        private readonly ?YiiQueue $queue = null,
        $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * Craft fires `EVENT_AFTER_SAVE` for drafts and revisions too, and those
     * are separate elements with their own IDs — verified against a real
     * save, not assumed. Since this plugin uses the Craft element ID as
     * Klaviyo's `external_id`, syncing them would create catalog entries
     * keyed to IDs that no customer can ever buy: a single CP publish
     * creates a revision, so an actively-edited store would accumulate one
     * junk catalog item *and* one junk catalog variant per edit, forever
     * (nothing ever deletes them — deletion only fires for real products).
     * They'd still show up in Klaviyo product blocks and recommendations.
     *
     * Propagation saves are skipped as well: on a multi-site install the
     * canonical save already synced that exact element ID, so re-syncing
     * per-site is a duplicate API call for identical data.
     */
    private function isSyncable(Element $element): bool
    {
        return $element->id !== null
            && !$element->getIsDraft()
            && !$element->getIsRevision()
            && !$element->propagating;
    }

    /**
     * Re-queues every published product and its variants for a full
     * catalog resync (e.g. after first install, or after Klaviyo-side data
     * loss) — the shared implementation behind both
     * `php craft commerce-klaviyo/reindex` ({@see \kernpfad\commerceklaviyo\console\controllers\ReindexController})
     * and the CP's "Sync now" button ({@see \kernpfad\commerceklaviyo\controllers\cp\SyncController}).
     *
     * Uses Klaviyo's bulk catalog jobs ({@see BulkCatalogSyncService}) in
     * chunks of up to 100 resources per API call — much faster than
     * enqueueing one {@see SyncCatalogItemJob}/{@see SyncCatalogVariantJob}
     * per element. Real-time saves on individual products/variants still
     * use the per-element jobs.
     *
     * Item chunk jobs are queued at a higher priority than variant chunks
     * so a single worker tends to finish parents before children, and each
     * variant chunk still {@see CatalogItemEnsurer ensures} its parent items
     * before the bulk variant call when workers run jobs out of order.
     *
     * @param ?callable(int, int): void $onProgress Called after each
     *   product (and its variants) is queued, as
     *   `(productCount, variantCount)` — e.g. for console progress output.
     * @return array{productCount: int, variantCount: int, itemJobCount: int, variantJobCount: int}|null null when
     *   another reindex is already running (mutex busy) rather than
     *   throwing, since "someone else is already doing this" isn't
     *   actually a failure the caller needs to handle differently from
     *   "nothing to do".
     */
    public function reindexAll(?callable $onProgress = null): ?array
    {
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(self::REINDEX_MUTEX_NAME, self::REINDEX_MUTEX_TIMEOUT)) {
            return null;
        }

        try {
            $productCount = 0;
            $variantCount = 0;
            $itemEntries = [];
            $variantEntries = [];

            /** @var Product $product */
            foreach (Product::find()->each() as $product) {
                $itemData = $this->buildItemSyncData($product);

                if ($itemData === null) {
                    continue;
                }

                $itemEntries[] = $itemData;
                $productCount++;

                foreach ($product->getVariants() as $variant) {
                    $variantData = $this->buildVariantSyncData($variant, $product, $itemData);

                    if ($variantData === null) {
                        continue;
                    }

                    $variantEntries[] = $variantData;
                    $variantCount++;
                }

                if ($onProgress !== null) {
                    $onProgress($productCount, $variantCount);
                }
            }

            $itemJobCount = $this->queueBulkItemChunks($itemEntries);
            $variantJobCount = $this->queueBulkVariantChunks($variantEntries);

            (new KlaviyoStatusService())->recordReindex([
                'productCount' => $productCount,
                'variantCount' => $variantCount,
                'itemJobCount' => $itemJobCount,
                'variantJobCount' => $variantJobCount,
                'mode' => 'bulk',
            ]);

            return [
                'productCount' => $productCount,
                'variantCount' => $variantCount,
                'itemJobCount' => $itemJobCount,
                'variantJobCount' => $variantJobCount,
            ];
        } finally {
            $mutex->release(self::REINDEX_MUTEX_NAME);
        }
    }

    public function syncProduct(Product $product): void
    {
        $itemData = $this->buildItemSyncData($product);

        if ($itemData === null) {
            return;
        }

        Queue::push(new SyncCatalogItemJob($itemData), queue: $this->queue);
    }

    /**
     * Queued on the variant's own `EVENT_AFTER_SAVE`, not the parent
     * product's — see this class's docblock for why.
     */
    public function syncVariant(Variant $variant): void
    {
        $product = $variant->getProduct();

        if ($product === null) {
            return;
        }

        $itemData = $this->buildItemSyncData($product);
        $variantData = $this->buildVariantSyncData($variant, $product, $itemData);

        if ($variantData === null || $itemData === null) {
            return;
        }

        Queue::push(new SyncCatalogVariantJob([
            'variantExternalId' => $variantData['variantExternalId'],
            'productTitle' => $variantData['productTitle'],
            'payload' => $variantData['payload'],
            'parentItemExternalId' => $itemData['itemExternalId'],
            'parentItemPayload' => $itemData['itemPayload'],
            'parentCategories' => $itemData['categories'],
        ]), queue: $this->queue);
    }

    /**
     * @return ?array{
     *   itemExternalId: string,
     *   productTitle: string,
     *   itemPayload: array<string, mixed>,
     *   categories: array<int, array{id: string, name: string}>
     * }
     */
    private function buildItemSyncData(Product $product): ?array
    {
        if (!$this->isSyncable($product)) {
            return null;
        }

        $itemExternalId = (string)$product->id;
        $title = $this->resolveTitle($product, null, $product->title ?? '');

        $images = $this->resolveImageUrls($product);

        $itemPayload = $this->payloadBuilder->buildItem(
            $itemExternalId,
            $title,
            $this->resolveDescription($product, $title),
            $product->getUrl() ?? '',
            (float)($product->getDefaultVariant()?->getPrice() ?? 0.0),
            $product->getStatus() === Element::STATUS_ENABLED,
            $images[0] ?? null,
            $this->buildMetadata($product),
            $images,
        );

        $itemPayload = $this->payloadEvents->dispatch(
            CommerceKlaviyo::EVENT_BEFORE_BUILD_CATALOG_ITEM_PAYLOAD,
            new BuildCatalogItemPayloadEvent($product, $itemPayload),
        );

        return [
            'itemExternalId' => $itemExternalId,
            'productTitle' => $title,
            'itemPayload' => $itemPayload,
            'categories' => $this->fieldResolver->resolveCategories($product, $this->categoriesFieldHandle),
        ];
    }

    /**
     * @param ?array{
     *   itemExternalId: string,
     *   productTitle: string,
     *   itemPayload: array<string, mixed>,
     *   categories: array<int, array{id: string, name: string}>
     * } $itemData
     * @return ?array{
     *   variantExternalId: string,
     *   productTitle: string,
     *   payload: array<string, mixed>,
     *   parentItemExternalId: string,
     *   parentItemPayload: array<string, mixed>,
     *   parentCategories: array<int, array{id: string, name: string}>
     * }
     */
    private function buildVariantSyncData(Variant $variant, Product $product, ?array $itemData): ?array
    {
        if (!$this->isSyncable($variant) || !$this->isSyncable($product) || $itemData === null) {
            return null;
        }

        $title = $this->resolveTitle($product, $variant, $variant->title ?: ($product->title ?? ''));
        $images = $this->resolveImageUrls($product, $variant);

        $payload = $this->payloadBuilder->buildVariant(
            (string)$variant->id,
            $itemData['itemExternalId'],
            $title,
            $this->resolveDescription($product, $title),
            $variant->getSku(),
            $this->resolveInventoryQuantity($variant),
            (float)($variant->getPrice() ?? 0.0),
            $product->getUrl() ?? '',
            $product->getStatus() === Element::STATUS_ENABLED,
            $images[0] ?? null,
            $this->buildMetadata($product, $variant),
            $images,
        );

        $payload = $this->payloadEvents->dispatch(
            CommerceKlaviyo::EVENT_BEFORE_BUILD_CATALOG_VARIANT_PAYLOAD,
            new BuildCatalogVariantPayloadEvent($variant, $product, $payload),
        );

        return [
            'variantExternalId' => (string)$variant->id,
            'productTitle' => $title,
            'payload' => $payload,
            'parentItemExternalId' => $itemData['itemExternalId'],
            'parentItemPayload' => $itemData['itemPayload'],
            'parentCategories' => $itemData['categories'],
        ];
    }

    /**
     * @param array<int, array{itemExternalId: string, productTitle: string, itemPayload: array<string, mixed>, categories: array<int, array{id: string, name: string}>}> $entries
     */
    private function queueBulkItemChunks(array $entries): int
    {
        $jobCount = 0;

        foreach (array_chunk($entries, BulkCatalogSyncService::CHUNK_SIZE) as $chunk) {
            Queue::push(new BulkCatalogItemsChunkJob(['entries' => $chunk]), priority: 1024, ttr: self::BULK_JOB_TTR_SECONDS, queue: $this->queue);
            $jobCount++;
        }

        return $jobCount;
    }

    /**
     * @param array<int, array{variantExternalId: string, productTitle: string, payload: array<string, mixed>, parentItemExternalId: string, parentItemPayload: array<string, mixed>, parentCategories: array<int, array{id: string, name: string}>}> $entries
     */
    private function queueBulkVariantChunks(array $entries): int
    {
        $jobCount = 0;

        foreach (array_chunk($entries, BulkCatalogSyncService::CHUNK_SIZE) as $chunk) {
            Queue::push(new BulkCatalogVariantsChunkJob(['entries' => $chunk]), priority: 512, ttr: self::BULK_JOB_TTR_SECONDS, queue: $this->queue);
            $jobCount++;
        }

        return $jobCount;
    }

    /**
     * A hard delete removes the catalog item outright; a soft delete
     * (trash — reversible in Craft) only unpublishes it, so a restore
     * doesn't come back to a brand-new, history-less Klaviyo entry. Craft
     * sets `$product->hardDelete` on the same element instance before
     * firing the delete events this is called from, for both single and
     * bulk deletes — see `craft\services\Elements::deleteElement()`.
     */
    public function deleteProduct(Product $product): void
    {
        // Same draft/revision guard as the sync side, and load-bearing here
        // too: Craft prunes superseded revisions in the background, which
        // fires this very event with a revision element. Without the guard
        // every pruned revision would fire a pointless delete call.
        if (!$this->isSyncable($product)) {
            return;
        }

        $itemExternalId = (string)$product->id;
        $productTitle = $product->title ?? '';

        if ($product->hardDelete) {
            Queue::push(new DeleteCatalogItemJob([
                'itemExternalId' => $itemExternalId,
                'productTitle' => $productTitle,
            ]), queue: $this->queue);

            return;
        }

        Queue::push(new UnpublishCatalogItemJob([
            'itemExternalId' => $itemExternalId,
            'productTitle' => $productTitle,
        ]), queue: $this->queue);
    }

    /**
     * Removes a single catalog variant, for when a variant is removed from a
     * product that itself still exists — deleting the whole product is
     * handled by {@see self::deleteProduct()}, and Klaviyo cascades variant
     * deletion from the parent item there. Without this, discontinuing one
     * size/colour left its catalog variant in Klaviyo forever, still
     * appearing in product blocks and still accepting back-in-stock
     * subscriptions for something that can no longer be bought.
     */
    public function deleteVariant(Variant $variant): void
    {
        if (!$this->isSyncable($variant)) {
            return;
        }

        $variantExternalId = (string)$variant->id;
        $productTitle = $variant->getProduct()->title ?? '';

        if ($variant->hardDelete) {
            Queue::push(new DeleteCatalogVariantJob([
                'variantExternalId' => $variantExternalId,
                'productTitle' => $productTitle,
            ]), queue: $this->queue);

            return;
        }

        Queue::push(new UnpublishCatalogVariantJob([
            'variantExternalId' => $variantExternalId,
            'productTitle' => $productTitle,
        ]), queue: $this->queue);
    }

    /**
     * Queued on every inventory movement — a lighter, inventory-only PATCH
     * rather than a full product re-sync.
     */
    public function syncVariantInventory(Variant $variant): void
    {
        if ($variant->id === null) {
            return;
        }

        Queue::push(new SyncVariantInventoryJob([
            'variantExternalId' => (string)$variant->id,
            'inventoryQuantity' => $this->resolveInventoryQuantity($variant),
            'published' => $variant->getStatus() === Element::STATUS_ENABLED,
        ]), queue: $this->queue);
    }

    /**
     * Maps a Commerce variant's stock into the quantity Klaviyo should see.
     * Untracked variants always get {@see UNTRACKED_INVENTORY_QUANTITY}.
     * Tracked variants optionally cap reporting via
     * {@see $inventoryReportingThreshold}: above the threshold Klaviyo gets
     * the same high placeholder (so low-inventory / back-in-stock logic
     * only activates once stock is actually near the configured floor).
     *
     * Pure enough to unit-test via {@see reportableQuantity()} without
     * booting Commerce.
     */
    private function resolveInventoryQuantity(Variant $variant): int
    {
        return self::reportableQuantity(
            $variant->inventoryTracked,
            $variant->inventoryTracked ? $variant->getStock() : 0,
            $this->inventoryReportingThreshold,
        );
    }

    /**
     * @internal unit-tested; prefer {@see resolveInventoryQuantity()} at call sites
     */
    public static function reportableQuantity(bool $inventoryTracked, int $stock, ?int $threshold): int
    {
        if (!$inventoryTracked) {
            return self::UNTRACKED_INVENTORY_QUANTITY;
        }

        if ($threshold !== null && $threshold > 0 && $stock > $threshold) {
            return self::UNTRACKED_INVENTORY_QUANTITY;
        }

        return $stock;
    }

    /**
     * Resolves {@see Settings::$titleFieldHandle}, checking the variant
     * first and falling back to the product — same order as
     * {@see buildMetadata()}, since a title override is just as likely to
     * be variant-specific (e.g. a size/colour baked into the name) as
     * product-wide. Falls back to `$fallback` (the element's own native
     * title) when the handle is unset or empty on both.
     */
    private function resolveTitle(Product $product, ?Variant $variant, string $fallback): string
    {
        if ($variant !== null) {
            $value = $this->fieldResolver->resolveText($variant, $this->titleFieldHandle);

            if ($value !== null) {
                return $value;
            }
        }

        return $this->fieldResolver->resolveText($product, $this->titleFieldHandle) ?? $fallback;
    }

    private function resolveDescription(ElementInterface $element, string $fallback): string
    {
        return $this->fieldResolver->resolveText($element, $this->descriptionFieldHandle) ?? $fallback;
    }

    /**
     * Image URLs for a catalog item or variant. Variant is checked first
     * (same order as title/metadata), then the product — so a colour-specific
     * Assets field on the variant wins over the shared product gallery.
     *
     * @return list<string>
     */
    private function resolveImageUrls(Product $product, ?Variant $variant = null): array
    {
        if ($variant !== null) {
            $urls = $this->fieldResolver->resolveImageUrls($variant, $this->imageFieldHandle);

            if ($urls !== []) {
                return $urls;
            }
        }

        return $this->fieldResolver->resolveImageUrls($product, $this->imageFieldHandle);
    }

    /**
     * Builds Klaviyo's `custom_metadata` object from
     * {@see Settings::$catalogFieldMappingTable} — project-specific data
     * (a strike-through price, a promo price, anything else) with no
     * dedicated catalog attribute of its own. Confirmed against Klaviyo's
     * own Catalogs API docs: valid on both catalog-item and
     * catalog-variant, as `custom_metadata` — NOT `metadata`, which was
     * the first (wrong) name tried here and gets rejected outright with
     * "not a valid field for the resource", on both resources, on both
     * create and update.
     *
     * When syncing a variant, each mapped handle is checked on the variant
     * first and falls back to the product — a promo price often varies per
     * variant, but merchants are free to set the field once on the product
     * instead when it doesn't. Reuses {@see ProfileMapper}, the same
     * generic `handle => value` mapper the profile-field-mapping feature
     * already uses; the shape is identical, so a second mapper class would
     * just be the same logic twice.
     *
     * @return array<string, mixed>
     */
    private function buildMetadata(Product $product, ?Variant $variant = null): array
    {
        if ($this->catalogFieldMapping === []) {
            return [];
        }

        $fieldValues = [];

        foreach (array_keys($this->catalogFieldMapping) as $fieldHandle) {
            $value = $variant !== null
                ? $this->fieldResolver->resolveValue($variant, $fieldHandle)
                : null;
            $value ??= $this->fieldResolver->resolveValue($product, $fieldHandle);

            if ($value !== null) {
                $fieldValues[$fieldHandle] = $value;
            }
        }

        return $this->fieldMapper->mapProperties($this->catalogFieldMapping, $fieldValues);
    }
}
