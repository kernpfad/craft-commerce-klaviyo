<?php

namespace kernpfad\commerceklaviyo\events;

use craft\commerce\elements\Variant;

/**
 * Fired before a back-in-stock subscription is posted to Klaviyo.
 */
class BuildBackInStockPayloadEvent extends ModifyPayloadEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public Variant $variant,
        public string $email,
        array $payload,
    ) {
        parent::__construct($payload);
    }
}
