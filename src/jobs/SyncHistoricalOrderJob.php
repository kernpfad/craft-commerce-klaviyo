<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use kernpfad\commerceklaviyo\CommerceKlaviyo;

/**
 * Replays Placed Order / Ordered Product for one historical Commerce order.
 */
class SyncHistoricalOrderJob extends BaseJob
{
    public int $orderId = 0;

    public function execute($queue): void
    {
        $this->setProgress($queue, 1);

        if ($this->orderId === 0) {
            return;
        }

        $order = Order::find()->id($this->orderId)->isCompleted()->one();
        if (!$order instanceof Order) {
            return;
        }

        $plugin = CommerceKlaviyo::getInstance();
        $plugin?->orderTracking->trackHistoricalPlacedOrder($order);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('commerce-klaviyo', 'Syncing historical order {id} to Klaviyo', [
            'id' => $this->orderId,
        ]);
    }
}
