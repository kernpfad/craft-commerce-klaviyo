<?php

namespace kernpfad\commerceklaviyo\events;

/**
 * Fired before a newsletter list-subscription job is queued or sent.
 */
class BuildNewsletterPayloadEvent extends ModifyPayloadEvent
{
    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $email,
        public string $listId,
        public ?string $firstName,
        public ?string $lastName,
        public array $properties,
        array $payload,
    ) {
        parent::__construct($payload);
    }
}
