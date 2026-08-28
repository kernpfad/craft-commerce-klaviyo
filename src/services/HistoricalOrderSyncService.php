<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\services;

use craft\commerce\elements\Order;
use craft\helpers\Queue;
use DateTimeInterface;
use kernpfad\commerceklaviyo\jobs\SyncHistoricalOrderJob;
use yii\base\Component;
use yii\queue\Queue as YiiQueue;

/**
 * Queues historical Placed Order replays for completed Commerce orders in a
 * date range (Foster-style backfill, Craft-5-ready via console + utility).
 */
class HistoricalOrderSyncService extends Component
{
    public function __construct(
        private readonly ?YiiQueue $queue = null,
        $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @return int Number of orders queued
     */
    public function enqueueDateRange(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $orders = Order::find()
            ->isCompleted()
            ->dateOrdered(['and', '>= ' . $from->format('Y-m-d H:i:s'), '<= ' . $to->format('Y-m-d H:i:s')])
            ->ids();

        $count = 0;
        foreach ($orders as $orderId) {
            Queue::push(new SyncHistoricalOrderJob([
                'orderId' => (int)$orderId,
            ]), queue: $this->queue);
            $count++;
        }

        return $count;
    }
}
