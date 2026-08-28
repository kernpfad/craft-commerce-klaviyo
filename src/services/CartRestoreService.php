<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\services;

use craft\commerce\elements\Order;

/**
 * Thin wrapper around Commerce's load-cart URL for abandoned-cart emails.
 * Keeps restore semantics in one place so the public action can stay dumb.
 */
class CartRestoreService
{
    public function __construct(
        private readonly OrderTrackingService $orderTracking = new OrderTrackingService(),
    ) {
    }

    /**
     * Absolute load-cart URL for an incomplete order, or null when the cart
     * cannot be restored (completed / URL unavailable).
     */
    public function resolveLoadCartUrl(Order $order): ?string
    {
        if ($order->isCompleted) {
            return null;
        }

        $url = $this->orderTracking->resolveCheckoutUrl($order);

        return $url !== '' ? $url : null;
    }
}
