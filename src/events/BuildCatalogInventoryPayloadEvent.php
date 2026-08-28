<?php

namespace kernpfad\commerceklaviyo\events;

/**
 * Fired before an inventory-only catalog-variant PATCH is sent.
 */
class BuildCatalogInventoryPayloadEvent extends ModifyPayloadEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $variantExternalId,
        public int $inventoryQuantity,
        public bool $published,
        array $payload,
    ) {
        parent::__construct($payload);
    }
}
