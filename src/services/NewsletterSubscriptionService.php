<?php

namespace kernpfad\commerceklaviyo\services;

use craft\helpers\Queue;
use kernpfad\commerceklaviyo\jobs\SubscribeNewsletterJob;
use yii\base\Component;
use yii\queue\Queue as YiiQueue;

/**
 * Queues a newsletter list subscription. Deliberately the single entry
 * point both signup paths go through — this plugin's own built-in
 * `newsletter/subscribe` form action and a bound Formie form submission —
 * so there's exactly one place that decides how a subscription reaches
 * Klaviyo.
 *
 * `$listId` is resolved once, from {@see \kernpfad\commerceklaviyo\models\Settings::$newsletterListId},
 * at the point this service is constructed (see `CommerceKlaviyo::init()`),
 * the same constructor-injection pattern already used for
 * `OrderTrackingService`/`CatalogSyncService`, keeping this service
 * decoupled from the plugin singleton.
 */
class NewsletterSubscriptionService extends Component
{
    public function __construct(
        private readonly ?string $listId = null,
        private readonly ?YiiQueue $queue = null,
        $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @param array<string, mixed> $properties Additional Klaviyo profile
     *     attributes, keyed by Klaviyo property key — as produced by
     *     {@see \kernpfad\commerceklaviyo\services\ProfileMapper::mapProperties()}.
     */
    public function subscribe(
        string $email,
        ?string $firstName = null,
        ?string $lastName = null,
        array $properties = [],
        ?string $listId = null,
    ): void {
        $listId = $listId ?? $this->listId;

        if ($listId === null || $listId === '') {
            return;
        }

        Queue::push(new SubscribeNewsletterJob([
            'email' => $email,
            'listId' => $listId,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'properties' => $properties,
        ]), queue: $this->queue);
    }
}
