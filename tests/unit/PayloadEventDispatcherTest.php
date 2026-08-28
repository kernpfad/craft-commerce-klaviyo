<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\events\BuildTrackEventPayloadEvent;
use kernpfad\commerceklaviyo\services\EventPayloadBuilder;
use kernpfad\commerceklaviyo\services\PayloadEventDispatcher;
use PHPUnit\Framework\TestCase;
use yii\base\Event;

class PayloadEventDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        Event::off(CommerceKlaviyo::class, CommerceKlaviyo::EVENT_BEFORE_BUILD_TRACK_EVENT_PAYLOAD);
        parent::tearDown();
    }

    public function testListenersCanModifyThePayloadBeforeItIsQueued(): void
    {
        Event::on(
            CommerceKlaviyo::class,
            CommerceKlaviyo::EVENT_BEFORE_BUILD_TRACK_EVENT_PAYLOAD,
            function(BuildTrackEventPayloadEvent $event): void {
                $event->payload['data']['attributes']['properties']['AgencyKey'] = 'yes';
            },
        );

        $payload = (new EventPayloadBuilder())->build('Placed Order', ['email' => 'a@example.com']);
        $event = new BuildTrackEventPayloadEvent('Placed Order', ['email' => 'a@example.com'], [], null, $payload);

        $result = (new PayloadEventDispatcher())->dispatch(
            CommerceKlaviyo::EVENT_BEFORE_BUILD_TRACK_EVENT_PAYLOAD,
            $event,
        );

        self::assertSame('yes', $result['data']['attributes']['properties']['AgencyKey']);
    }
}
