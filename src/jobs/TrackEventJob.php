<?php

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;

/**
 * Sends a single, already-built Klaviyo Events API payload. Deliberately
 * does the payload building *before* queueing (in the service that queues
 * this job), not here — this job's only job is the network call, wrapped
 * so a Klaviyo outage or bad response degrades to a logged failure and a
 * queue retry, never an exception surfacing anywhere near a customer
 * request (this job runs on the queue worker, never inline).
 *
 * @property-read string $description
 */
class TrackEventJob extends BaseKlaviyoJob
{
    /**
     * @var array<string, mixed>
     */
    public array $payload = [];

    public string $metricName = '';

    public function execute($queue): void
    {
        $this->withKlaviyoClient(function(KlaviyoClient $client): void {
            $client->post('events', $this->payload);
        });
    }

    protected function skippedMessage(): string
    {
        return 'Commerce Klaviyo: skipped tracking event, no API key configured.';
    }

    protected function errorMessage(\Throwable $e): string
    {
        return "Commerce Klaviyo: failed to send \"{$this->metricName}\" event: {$e->getMessage()}";
    }

    protected function failureCategory(): ?string
    {
        return KlaviyoStatusService::CATEGORY_TRACK;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-klaviyo', 'Sending "{metric}" event to Klaviyo', ['metric' => $this->metricName]);
    }
}
