<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\services;

use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Queue;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\events\BuildTrackEventPayloadEvent;
use kernpfad\commerceklaviyo\jobs\TrackEventJob;
use kernpfad\commerceklaviyo\jobs\UpsertProfileJob;
use kernpfad\commerceklaviyo\models\TrackActionRequest;
use yii\base\Component;
use yii\queue\Queue as YiiQueue;

/**
 * Queues identify / custom track / list subscribe work from public Twig forms.
 * Never calls Klaviyo inline — same reliability rule as order tracking.
 */
class TrackActionsService extends Component
{
    public function __construct(
        private readonly EventPayloadBuilder $payloadBuilder = new EventPayloadBuilder(),
        private readonly PayloadEventDispatcher $payloadEvents = new PayloadEventDispatcher(),
        private readonly NewsletterSubscriptionService $newsletterSubscription = new NewsletterSubscriptionService(),
        private readonly ?YiiQueue $queue = null,
        $config = [],
    ) {
        parent::__construct($config);
    }

    public function handle(TrackActionRequest $request): void
    {
        if ($request->email === null) {
            return;
        }

        $this->queueIdentify($request);
        $this->queueEvent($request);
        $this->queueListSubscriptions($request);
    }

    private function queueIdentify(TrackActionRequest $request): void
    {
        $attributes = $request->profile;
        unset($attributes['email']);

        Queue::push(new UpsertProfileJob([
            'email' => $request->email,
            'attributes' => $attributes,
        ]), queue: $this->queue);
    }

    private function queueEvent(TrackActionRequest $request): void
    {
        if ($request->eventName === null) {
            return;
        }

        if ($request->trackOrder) {
            $this->queueOrderEvent($request);

            return;
        }

        $profile = ['email' => $request->email];
        foreach ($request->profile as $key => $value) {
            if ($key === 'email') {
                continue;
            }
            $profile[$key] = $value;
        }

        $payload = $this->payloadBuilder->build(
            $request->eventName,
            $profile,
            $request->eventProperties,
            $request->eventValue,
            $request->eventUniqueId,
        );

        $payload = $this->payloadEvents->dispatch(
            CommerceKlaviyo::EVENT_BEFORE_BUILD_TRACK_EVENT_PAYLOAD,
            new BuildTrackEventPayloadEvent(
                $request->eventName,
                $profile,
                $request->eventProperties,
                null,
                $payload,
            ),
        );

        Queue::push(new TrackEventJob([
            'payload' => $payload,
            'metricName' => $request->eventName,
        ]), queue: $this->queue);
    }

    private function queueOrderEvent(TrackActionRequest $request): void
    {
        $commerce = Commerce::getInstance();
        if ($commerce === null) {
            return;
        }

        $order = null;
        if ($request->orderId !== null) {
            $order = Order::find()->id($request->orderId)->one();
        } else {
            $order = $commerce->getCarts()->getCart();
        }

        if (!$order instanceof Order || $request->eventName === null || $request->email === null) {
            return;
        }

        $properties = array_merge([
            'OrderId' => $order->reference ?: $order->getShortNumber(),
            'ItemNames' => array_map(
                static fn(LineItem $li): string => $li->getDescription(),
                $order->getLineItems(),
            ),
            '$value' => (float)$order->getTotal(),
        ], $request->eventProperties);

        $payload = $this->payloadBuilder->build(
            $request->eventName,
            ['email' => $request->email],
            $properties,
            $request->eventValue ?? (float)$order->getTotal(),
            $request->eventUniqueId ?? (string)$order->number,
        );

        $payload = $this->payloadEvents->dispatch(
            CommerceKlaviyo::EVENT_BEFORE_BUILD_TRACK_EVENT_PAYLOAD,
            new BuildTrackEventPayloadEvent(
                $request->eventName,
                ['email' => $request->email],
                $properties,
                $order,
                $payload,
            ),
        );

        Queue::push(new TrackEventJob([
            'payload' => $payload,
            'metricName' => $request->eventName,
        ]), queue: $this->queue);
    }

    private function queueListSubscriptions(TrackActionRequest $request): void
    {
        // Always use Klaviyo's subscribe endpoint (DOI-aware), not a
        // create-only list membership call that would skip double opt-in.
        if ($request->listIds === [] || $request->email === null) {
            return;
        }

        $firstName = isset($request->profile['first_name']) && is_string($request->profile['first_name'])
            ? $request->profile['first_name']
            : null;
        $lastName = isset($request->profile['last_name']) && is_string($request->profile['last_name'])
            ? $request->profile['last_name']
            : null;

        $properties = $request->profile;
        unset($properties['email'], $properties['first_name'], $properties['last_name']);

        foreach ($request->listIds as $listId) {
            $this->newsletterSubscription->subscribe(
                $request->email,
                $firstName,
                $lastName,
                $properties,
                $listId,
            );
        }
    }
}
