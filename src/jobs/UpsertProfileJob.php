<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;

/**
 * Upserts a Klaviyo profile from a public identify/track form action.
 *
 * @property-read string $description
 */
class UpsertProfileJob extends BaseKlaviyoJob
{
    public string $email = '';

    /**
     * @var array<string, mixed>
     */
    public array $attributes = [];

    public function execute($queue): void
    {
        $this->withKlaviyoClient(function(KlaviyoClient $client): void {
            $attributes = $this->attributes;
            $attributes['email'] = $this->email;

            $client->post('profile-import', [
                'data' => [
                    'type' => 'profile',
                    'attributes' => $attributes,
                ],
            ]);
        });
    }

    protected function skippedMessage(): string
    {
        return 'Commerce Klaviyo: skipped profile upsert, no API key configured.';
    }

    protected function errorMessage(\Throwable $e): string
    {
        return "Commerce Klaviyo: failed to upsert profile {$this->email}: {$e->getMessage()}";
    }

    protected function failureCategory(): ?string
    {
        return KlaviyoStatusService::CATEGORY_TRACK;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-klaviyo', 'Upserting Klaviyo profile for {email}', ['email' => $this->email]);
    }
}
