<?php

namespace kernpfad\commerceklaviyo\services;

use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\events\ModifyPayloadEvent;
use yii\base\Event;

/**
 * Triggers plugin-owned payload-modification events so agencies can adjust
 * Klaviyo request bodies without forking payload builders.
 */
class PayloadEventDispatcher
{
    /**
     * @return array<string, mixed>
     */
    public function dispatch(string $eventName, ModifyPayloadEvent $event): array
    {
        Event::trigger(CommerceKlaviyo::class, $eventName, $event);

        return $event->payload;
    }
}
