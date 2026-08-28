<?php

namespace kernpfad\commerceklaviyo\records;

use craft\db\ActiveRecord;

/**
 * Idempotency guard for events with no natural "only happens once"
 * trigger in Commerce — currently just `Started Checkout`, which this
 * plugin infers from an incomplete cart getting an email address for the
 * first time (see {@see \kernpfad\commerceklaviyo\services\OrderTrackingService}),
 * a heuristic that would otherwise re-fire on every subsequent cart save.
 *
 * @property int $id
 * @property int $orderId
 * @property string $eventType
 */
class TrackedEventRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%commerceklaviyo_trackedevents}}';
    }
}
