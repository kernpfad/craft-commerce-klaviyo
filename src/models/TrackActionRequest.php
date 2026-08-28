<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\models;

/**
 * Parsed body for public Twig track/identify actions.
 */
final class TrackActionRequest
{
    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $eventProperties
     * @param list<string> $listIds
     */
    public function __construct(
        public readonly ?string $email,
        public readonly array $profile,
        public readonly ?string $eventName,
        public readonly ?string $eventUniqueId,
        public readonly ?float $eventValue,
        public readonly ?string $eventValueCurrency,
        public readonly array $eventProperties,
        public readonly array $listIds,
        public readonly bool $subscribe,
        public readonly bool $trackOrder,
        public readonly ?int $orderId,
        public readonly ?string $forward,
    ) {
    }
}
