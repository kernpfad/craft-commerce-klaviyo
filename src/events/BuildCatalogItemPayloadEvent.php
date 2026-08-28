<?php

namespace kernpfad\commerceklaviyo\events;

use craft\commerce\elements\Product;

/**
 * Fired before a catalog-item sync job is queued.
 */
class BuildCatalogItemPayloadEvent extends ModifyPayloadEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public Product $product,
        array $payload,
    ) {
        parent::__construct($payload);
    }
}
