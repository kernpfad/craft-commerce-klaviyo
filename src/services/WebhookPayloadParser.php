<?php

namespace kernpfad\commerceklaviyo\services;

/**
 * Parses Klaviyo system-webhook payloads to extract profile emails and
 * classify consent-related topics. Framework-free for unit testing.
 */
class WebhookPayloadParser
{
    private const OPT_OUT_TOPICS = [
        'event:klaviyo.unsubscribed_from_email_marketing',
        'event:klaviyo.manually_suppressed_from_email_marketing',
    ];

    private const OPT_IN_TOPICS = [
        'event:klaviyo.subscribed_to_email_marketing',
        'event:klaviyo.manually_unsuppressed_from_email_marketing',
    ];

    /**
     * @param array<string, mixed> $body decoded JSON request body
     * @return list<array{email: string, optedOut: bool}>
     */
    public function parseConsentChanges(array $body): array
    {
        $changes = [];

        foreach ($body['data'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $topic = $item['topic'] ?? null;

            if (!is_string($topic)) {
                continue;
            }

            $optedOut = match (true) {
                $this->isOptOutTopic($topic) => true,
                $this->isOptInTopic($topic) => false,
                default => null,
            };

            if ($optedOut === null) {
                continue;
            }

            $email = $this->extractEmailFromEventItem($item);

            if ($email === null) {
                continue;
            }

            $changes[] = ['email' => $email, 'optedOut' => $optedOut];
        }

        return $changes;
    }

    public function isOptOutTopic(string $topic): bool
    {
        return in_array($topic, self::OPT_OUT_TOPICS, true);
    }

    public function isOptInTopic(string $topic): bool
    {
        return in_array($topic, self::OPT_IN_TOPICS, true);
    }

    /**
     * @param array<string, mixed> $item
     */
    public function extractEmailFromEventItem(array $item): ?string
    {
        $payload = $item['payload'] ?? [];

        if (!is_array($payload)) {
            return null;
        }

        foreach ($payload['included'] ?? [] as $included) {
            if (!is_array($included) || ($included['type'] ?? '') !== 'profile') {
                continue;
            }

            $attributes = $included['attributes'] ?? [];

            if (!is_array($attributes)) {
                continue;
            }

            $email = $attributes['email'] ?? null;

            if (is_string($email) && $email !== '') {
                return $email;
            }
        }

        $eventProperties = $payload['data']['attributes']['event_properties'] ?? [];

        if (is_array($eventProperties)) {
            foreach (['email', '$email', 'Email'] as $key) {
                $value = $eventProperties[$key] ?? null;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
