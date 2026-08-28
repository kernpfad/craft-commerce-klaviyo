<?php

namespace kernpfad\commerceklaviyo\services;

use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\models\Transaction;
use craft\helpers\Queue;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\events\BuildTrackEventPayloadEvent;
use kernpfad\commerceklaviyo\jobs\TrackEventJob;
use kernpfad\commerceklaviyo\models\KlaviyoMetric;
use kernpfad\commerceklaviyo\records\TrackedEventRecord;
use yii\base\Component;
use yii\queue\Queue as YiiQueue;

/**
 * Builds and queues the standard Klaviyo ecommerce events (see
 * {@see KlaviyoMetric}) from real Commerce order data. Building the
 * payload happens synchronously (cheap, in-process, no network I/O); only
 * the actual send is queued — on whichever queue component `$queue` is
 * ({@see \kernpfad\commerceklaviyo\models\Settings::$queueComponentId}),
 * or Craft's own default when null.
 *
 * Every event's `profile` is enriched with
 * {@see \kernpfad\commerceklaviyo\models\Settings::$profileFieldMapping}
 * via {@see ProfileMapper}, read from the order's associated
 * `craft\elements\User` when one exists, or from its billing/shipping
 * address on guest checkout.
 */
class OrderTrackingService extends Component
{
    /**
     * @param array<string, string> $profileFieldMapping craftFieldHandle => klaviyoPropertyKey
     */
    public function __construct(
        private readonly EventPayloadBuilder $payloadBuilder = new EventPayloadBuilder(),
        private readonly ProfileMapper $profileMapper = new ProfileMapper(),
        private readonly OrderProfileFieldExtractor $profileFieldExtractor = new OrderProfileFieldExtractor(),
        private readonly PayloadEventDispatcher $payloadEvents = new PayloadEventDispatcher(),
        private readonly array $profileFieldMapping = [],
        private readonly ?YiiQueue $queue = null,
        $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * Fires once per cart, the first time it has an email address — this
     * plugin's definition of "checkout started" in the absence of a
     * distinct Commerce checkout-step event (see the README for the
     * reasoning). Idempotent via {@see TrackedEventRecord}.
     */
    public function trackStartedCheckout(Order $order): void
    {
        $email = $order->getEmail();

        if ($email === null || $order->id === null) {
            return;
        }

        if (!$this->markTrackedOnce($order->id, KlaviyoMetric::STARTED_CHECKOUT)) {
            return;
        }

        $this->queueEvent(
            KlaviyoMetric::STARTED_CHECKOUT,
            $this->buildProfile($email, $order),
            [
                'ItemNames' => array_map(fn(LineItem $li): string => $li->getDescription(), $order->getLineItems()),
                '$value' => (float)$order->getTotal(),
                'CheckoutURL' => $this->resolveCheckoutUrl($order),
            ],
            (float)$order->getTotal(),
            (string)$order->number,
            $order,
        );
    }

    public function trackPlacedOrder(Order $order, ?\DateTimeInterface $occurredAt = null): void
    {
        $email = $order->getEmail();

        if ($email === null) {
            return;
        }

        $profile = $this->buildProfile($email, $order);
        $time = $occurredAt?->format('Y-m-d\TH:i:sP');

        $this->queueEvent(
            KlaviyoMetric::PLACED_ORDER,
            $profile,
            [
                'OrderId' => $order->reference ?: $order->getShortNumber(),
                'Categories' => [],
                'ItemNames' => array_map(fn(LineItem $li): string => $li->getDescription(), $order->getLineItems()),
                '$value' => (float)$order->getTotal(),
            ],
            (float)$order->getTotal(),
            (string)$order->number,
            $order,
            $time,
        );

        foreach ($order->getLineItems() as $lineItem) {
            $this->queueEvent(
                KlaviyoMetric::ORDERED_PRODUCT,
                $profile,
                [
                    'ProductID' => $lineItem->purchasableId,
                    'SKU' => $lineItem->getSku(),
                    'ProductName' => $lineItem->getDescription(),
                    'Quantity' => $lineItem->qty,
                    'ItemPrice' => (float)$lineItem->getSalePrice(),
                    'RowTotal' => (float)$lineItem->getSubtotal(),
                ],
                (float)$lineItem->getSubtotal(),
                $order->number . '-' . $lineItem->id,
                $order,
                $time,
            );
        }
    }

    /**
     * Re-send Placed Order / Ordered Product for a past completed order,
     * stamped with the order's original {@see Order::$dateOrdered}.
     */
    public function trackHistoricalPlacedOrder(Order $order): void
    {
        if (!$order->isCompleted) {
            return;
        }

        $this->trackPlacedOrder($order, $order->dateOrdered);
    }

    /**
     * @param string[] $fulfilledStatusHandles
     * @param string[] $cancelledStatusHandles
     */
    public function trackStatusChange(
        Order $order,
        string $newStatusHandle,
        array $fulfilledStatusHandles,
        array $cancelledStatusHandles,
    ): void {
        $email = $order->getEmail();

        if ($email === null) {
            return;
        }

        $metric = match (true) {
            in_array($newStatusHandle, $fulfilledStatusHandles, true) => KlaviyoMetric::FULFILLED_ORDER,
            in_array($newStatusHandle, $cancelledStatusHandles, true) => KlaviyoMetric::CANCELLED_ORDER,
            default => null,
        };

        if ($metric === null) {
            return;
        }

        $this->queueEvent(
            $metric,
            $this->buildProfile($email, $order),
            ['OrderId' => $order->reference ?: $order->getShortNumber()],
            (float)$order->getTotal(),
            $order->number . '-' . $newStatusHandle,
            $order,
        );
    }

    public function trackRefund(Order $order, Transaction $refundTransaction): void
    {
        $email = $order->getEmail();

        if ($email === null) {
            return;
        }

        $this->queueEvent(
            KlaviyoMetric::REFUNDED_ORDER,
            $this->buildProfile($email, $order),
            ['OrderId' => $order->reference ?: $order->getShortNumber()],
            (float)$refundTransaction->paymentAmount,
            $order->number . '-refund-' . $refundTransaction->id,
            $order,
        );
    }

    /**
     * The event `profile`: the order's email plus, if the order has an
     * associated `craft\elements\User`, or from the order's
     * billing/shipping address on guest checkout.
     * Public (not just used internally) so integration tests can verify the
     * mapped properties actually reach the profile without needing to
     * inspect a queued job's internals — same reasoning as
     * `CatalogSyncService::buildVariantPayloads()` being public.
     *
     * @return array<string, mixed>
     */
    public function buildProfile(string $email, Order $order): array
    {
        $profile = ['email' => $email];

        if ($this->profileFieldMapping === []) {
            return $profile;
        }

        $fieldValues = $this->profileFieldExtractor->extract(
            array_keys($this->profileFieldMapping),
            $order->getCustomer(),
            $order->getBillingAddress(),
            $order->getShippingAddress(),
        );

        return array_merge($profile, $this->profileMapper->mapProperties($this->profileFieldMapping, $fieldValues));
    }

    /**
     * Absolute URL the customer can use to resume this cart. Commerce's own
     * {@see Order::getLoadCartUrl()} is the canonical source; public so
     * integration tests can verify the property without queue inspection.
     *
     * `Order::getLoadCartUrl()` builds a front-end URL and, via Commerce's
     * `Carts::getLoadCartUrl()`, calls `$request->setIsCpRequest(false)` --
     * a method that only exists on `craft\web\Request`. Any console-context
     * order save (an import script, a migration, a queue job run via `php
     * craft queue/exec`) that reaches this crashes the entire save with an
     * UnknownMethodException, not just this tracking call, since it runs
     * synchronously inside `Order::EVENT_AFTER_SAVE`. Treat that (or any
     * other failure building the URL) as "no URL available" rather than
     * letting it break the save.
     */
    public function resolveCheckoutUrl(Order $order): string
    {
        try {
            $url = $order->getLoadCartUrl();
        } catch (\Throwable) {
            return '';
        }

        if (is_string($url) && $url !== '') {
            return $url;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $properties
     */
    private function queueEvent(
        string $metric,
        array $profile,
        array $properties,
        ?float $value,
        string $uniqueId,
        ?Order $order = null,
        ?string $time = null,
    ): void {
        $payload = $this->payloadBuilder->build($metric, $profile, $properties, $value, $uniqueId, $time);

        $payload = $this->payloadEvents->dispatch(
            CommerceKlaviyo::EVENT_BEFORE_BUILD_TRACK_EVENT_PAYLOAD,
            new BuildTrackEventPayloadEvent($metric, $profile, $properties, $order, $payload),
        );

        Queue::push(new TrackEventJob([
            'payload' => $payload,
            'metricName' => $metric,
        ]), queue: $this->queue);
    }

    private function markTrackedOnce(int $orderId, string $eventType): bool
    {
        if (TrackedEventRecord::findOne(['orderId' => $orderId, 'eventType' => $eventType]) !== null) {
            return false;
        }

        $record = new TrackedEventRecord();
        $record->orderId = $orderId;
        $record->eventType = $eventType;

        return $record->save();
    }
}
