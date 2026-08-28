<?php

namespace kernpfad\commerceklaviyo\events;

use yii\base\Event;

/**
 * Base event for agency hooks that need to adjust a Klaviyo API payload
 * before it is queued or sent. Listeners mutate {@see $payload} in place.
 */
class ModifyPayloadEvent extends Event
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public array $payload,
    ) {
        parent::__construct();
    }
}
