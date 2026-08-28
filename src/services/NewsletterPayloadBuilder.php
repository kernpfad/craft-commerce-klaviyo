<?php

namespace kernpfad\commerceklaviyo\services;

/**
 * Pure construction of the payload for Klaviyo's Bulk Subscribe Profiles
 * endpoint (`profile-subscription-bulk-create-jobs`, verified against
 * https://developers.klaviyo.com/en/reference/subscribe_profiles and
 * cross-checked against Formie's own shipped Klaviyo email-marketing
 * integration). No pre-existing profile ID is required — Klaviyo creates
 * the profile inline from the attributes given if none matches the email
 * yet. Framework-free so it's unit-testable without a Klaviyo client or
 * Craft boot.
 */
class NewsletterPayloadBuilder
{
    /**
     * @param array<string, mixed> $properties Additional Klaviyo profile
     *     attributes, keyed by Klaviyo property key — as produced by
     *     {@see \kernpfad\commerceklaviyo\services\ProfileMapper::mapProperties()}.
     *     Merged into the profile's attributes alongside email/first_name/last_name.
     * @return array<string, mixed>
     */
    public function buildListSubscription(
        string $email,
        string $listId,
        ?string $firstName = null,
        ?string $lastName = null,
        array $properties = [],
    ): array {
        $attributes = array_filter([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ], fn(?string $value): bool => $value !== null && $value !== '');

        $attributes = array_merge($attributes, $properties);

        $attributes['subscriptions'] = [
            'email' => [
                'marketing' => [
                    'consent' => 'SUBSCRIBED',
                ],
            ],
        ];

        return [
            'data' => [
                'type' => 'profile-subscription-bulk-create-job',
                'attributes' => [
                    'profiles' => [
                        'data' => [
                            [
                                'type' => 'profile',
                                'attributes' => $attributes,
                            ],
                        ],
                    ],
                ],
                'relationships' => [
                    'list' => [
                        'data' => [
                            'type' => 'list',
                            'id' => $listId,
                        ],
                    ],
                ],
            ],
        ];
    }
}
