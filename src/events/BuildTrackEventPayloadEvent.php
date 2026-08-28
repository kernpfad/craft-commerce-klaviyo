<?php

namespace kernpfad\commerceklaviyo\events;

use craft\commerce\elements\Order;

/**
 * Fired before an ecommerce metric event job is queued.
 */
class BuildTrackEventPayloadEvent extends ModifyPayloadEvent
{
    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $metricName,
        public array $profile,
        public array $properties,
        public ?Order $order,
        array $payload,
    ) {
        parent::__construct($payload);
    }
}
