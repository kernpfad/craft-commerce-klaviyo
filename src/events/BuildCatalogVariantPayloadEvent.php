<?php

namespace kernpfad\commerceklaviyo\events;

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;

/**
 * Fired before a catalog-variant sync job is queued.
 */
class BuildCatalogVariantPayloadEvent extends ModifyPayloadEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public Variant $variant,
        public Product $product,
        array $payload,
    ) {
        parent::__construct($payload);
    }
}
