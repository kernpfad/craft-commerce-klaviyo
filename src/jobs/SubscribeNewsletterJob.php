<?php

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\events\BuildNewsletterPayloadEvent;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;
use kernpfad\commerceklaviyo\services\NewsletterPayloadBuilder;
use kernpfad\commerceklaviyo\services\PayloadEventDispatcher;

/**
 * Subscribes a single email address to a Klaviyo list. Runs on the queue
 * like every other Klaviyo API call this plugin makes — both signup paths
 * (this plugin's own built-in form action and a bound Formie form
 * submission) queue it rather than calling Klaviyo inline, so a Klaviyo
 * outage never surfaces as an error on a customer-facing form, Formie's
 * own included.
 *
 * @property-read string $description
 */
class SubscribeNewsletterJob extends BaseKlaviyoJob
{
    public string $email = '';

    public string $listId = '';

    public ?string $firstName = null;

    public ?string $lastName = null;

    /**
     * Additional Klaviyo profile attributes, keyed by Klaviyo property key.
     *
     * @var array<string, mixed>
     */
    public array $properties = [];

    public function execute($queue): void
    {
        $this->withKlaviyoClient(function(KlaviyoClient $client): void {
            $payload = (new NewsletterPayloadBuilder())->buildListSubscription(
                $this->email,
                $this->listId,
                $this->firstName,
                $this->lastName,
                $this->properties,
            );

            $payload = (new PayloadEventDispatcher())->dispatch(
                CommerceKlaviyo::EVENT_BEFORE_BUILD_NEWSLETTER_PAYLOAD,
                new BuildNewsletterPayloadEvent(
                    $this->email,
                    $this->listId,
                    $this->firstName,
                    $this->lastName,
                    $this->properties,
                    $payload,
                ),
            );

            $client->post('profile-subscription-bulk-create-jobs', $payload);
        });
    }

    protected function skippedMessage(): string
    {
        return 'Commerce Klaviyo: skipped newsletter subscription, no API key configured.';
    }

    protected function errorMessage(\Throwable $e): string
    {
        return "Commerce Klaviyo: failed to subscribe {$this->email} to the newsletter list: {$e->getMessage()}";
    }

    protected function failureCategory(): ?string
    {
        return KlaviyoStatusService::CATEGORY_TRACK;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-klaviyo', 'Subscribing {email} to the Klaviyo newsletter list', ['email' => $this->email]);
    }
}
