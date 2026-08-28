<?php

namespace kernpfad\commerceklaviyo\models;

/**
 * Klaviyo's own standard/reserved ecommerce metric names — verified against
 * Klaviyo's developer docs, not guessed. Using these exact strings (rather
 * than inventing custom event names) is what lets a merchant use Klaviyo's
 * pre-built flow templates (Abandoned Checkout, Fulfilled/Cancelled Order
 * notices, Refund confirmations) without rebuilding the flow trigger.
 */
class KlaviyoMetric
{
    public const STARTED_CHECKOUT = 'Started Checkout';
    public const PLACED_ORDER = 'Placed Order';
    public const ORDERED_PRODUCT = 'Ordered Product';
    public const FULFILLED_ORDER = 'Fulfilled Order';
    public const CANCELLED_ORDER = 'Cancelled Order';
    public const REFUNDED_ORDER = 'Refunded Order';
}
