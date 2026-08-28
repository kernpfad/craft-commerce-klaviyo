<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\services\EventPayloadBuilder;
use PHPUnit\Framework\TestCase;

class EventPayloadBuilderTest extends TestCase
{
    private EventPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new EventPayloadBuilder();
    }

    public function testBuildsTheMinimalRequiredShape(): void
    {
        $payload = $this->builder->build('Placed Order', ['email' => 'a@example.com']);

        self::assertSame([
            'data' => [
                'type' => 'event',
                'attributes' => [
                    'metric' => ['name' => 'Placed Order'],
                    'profile' => ['email' => 'a@example.com'],
                    'properties' => [],
                ],
            ],
        ], $payload);
    }

    public function testIncludesOptionalFieldsOnlyWhenProvided(): void
    {
        $payload = $this->builder->build(
            'Ordered Product',
            ['email' => 'a@example.com'],
            ['SKU' => 'ABC-1'],
            49.0,
            'order-1-line-1',
            '2026-07-28T12:00:00Z',
        );

        $attributes = $payload['data']['attributes'];

        self::assertSame(49.0, $attributes['value']);
        self::assertSame('order-1-line-1', $attributes['unique_id']);
        self::assertSame('2026-07-28T12:00:00Z', $attributes['time']);
        self::assertSame(['SKU' => 'ABC-1'], $attributes['properties']);
    }

    public function testOmitsValueUniqueIdAndTimeWhenNotProvided(): void
    {
        $payload = $this->builder->build('Started Checkout', ['email' => 'a@example.com']);

        $attributes = $payload['data']['attributes'];

        self::assertArrayNotHasKey('value', $attributes);
        self::assertArrayNotHasKey('unique_id', $attributes);
        self::assertArrayNotHasKey('time', $attributes);
    }
}
