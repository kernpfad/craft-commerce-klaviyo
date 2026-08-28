<?php

namespace kernpfad\commerceklaviyo\services;

/**
 * Pure construction of a Klaviyo Events API request body (the
 * `{"data": {"type": "event", "attributes": {...}}}` shape documented at
 * https://developers.klaviyo.com/en/reference/create_event). Framework-free
 * so it's unit-testable without a Klaviyo client or Craft boot.
 */
class EventPayloadBuilder
{
    /**
     * @param array<string, mixed> $profile at least one of id/email/phone_number
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    public function build(
        string $metricName,
        array $profile,
        array $properties = [],
        ?float $value = null,
        ?string $uniqueId = null,
        ?string $time = null,
    ): array {
        $attributes = [
            'metric' => ['name' => $metricName],
            'profile' => $profile,
            'properties' => $properties,
        ];

        if ($value !== null) {
            $attributes['value'] = $value;
        }

        if ($uniqueId !== null) {
            $attributes['unique_id'] = $uniqueId;
        }

        if ($time !== null) {
            $attributes['time'] = $time;
        }

        return [
            'data' => [
                'type' => 'event',
                'attributes' => $attributes,
            ],
        ];
    }
}
