<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\services\NewsletterPayloadBuilder;
use PHPUnit\Framework\TestCase;

class NewsletterPayloadBuilderTest extends TestCase
{
    private NewsletterPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new NewsletterPayloadBuilder();
    }

    public function testBuildsTheMinimalRequiredShape(): void
    {
        $payload = $this->builder->buildListSubscription('a@example.com', 'ABC123');

        self::assertSame([
            'data' => [
                'type' => 'profile-subscription-bulk-create-job',
                'attributes' => [
                    'profiles' => [
                        'data' => [
                            [
                                'type' => 'profile',
                                'attributes' => [
                                    'email' => 'a@example.com',
                                    'subscriptions' => [
                                        'email' => [
                                            'marketing' => [
                                                'consent' => 'SUBSCRIBED',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'relationships' => [
                    'list' => [
                        'data' => [
                            'type' => 'list',
                            'id' => 'ABC123',
                        ],
                    ],
                ],
            ],
        ], $payload);
    }

    public function testIncludesFirstAndLastNameOnlyWhenProvided(): void
    {
        $payload = $this->builder->buildListSubscription('a@example.com', 'ABC123', 'Ada', 'Lovelace');

        $attributes = $payload['data']['attributes']['profiles']['data'][0]['attributes'];

        self::assertSame('Ada', $attributes['first_name']);
        self::assertSame('Lovelace', $attributes['last_name']);
    }

    public function testOmitsFirstAndLastNameWhenNotProvided(): void
    {
        $payload = $this->builder->buildListSubscription('a@example.com', 'ABC123');

        $attributes = $payload['data']['attributes']['profiles']['data'][0]['attributes'];

        self::assertArrayNotHasKey('first_name', $attributes);
        self::assertArrayNotHasKey('last_name', $attributes);
    }

    public function testAlwaysSetsEmailMarketingConsentToSubscribed(): void
    {
        $payload = $this->builder->buildListSubscription('a@example.com', 'ABC123');

        $attributes = $payload['data']['attributes']['profiles']['data'][0]['attributes'];

        self::assertSame('SUBSCRIBED', $attributes['subscriptions']['email']['marketing']['consent']);
    }

    public function testUsesTheGivenListIdInTheRelationship(): void
    {
        $payload = $this->builder->buildListSubscription('a@example.com', 'my-list-id');

        self::assertSame('my-list-id', $payload['data']['relationships']['list']['data']['id']);
    }

    public function testMergesAdditionalPropertiesIntoTheAttributes(): void
    {
        $payload = $this->builder->buildListSubscription(
            'a@example.com',
            'ABC123',
            'Ada',
            'Lovelace',
            ['phone_number' => '+15551234567', 'loyalty_tier' => 'Gold'],
        );

        $attributes = $payload['data']['attributes']['profiles']['data'][0]['attributes'];

        self::assertSame('+15551234567', $attributes['phone_number']);
        self::assertSame('Gold', $attributes['loyalty_tier']);
        self::assertSame('Ada', $attributes['first_name']);
    }

    public function testAdditionalPropertiesCannotOverrideTheMarketingConsent(): void
    {
        $payload = $this->builder->buildListSubscription(
            'a@example.com',
            'ABC123',
            null,
            null,
            ['subscriptions' => ['email' => ['marketing' => ['consent' => 'UNSUBSCRIBED']]]],
        );

        $attributes = $payload['data']['attributes']['profiles']['data'][0]['attributes'];

        self::assertSame('SUBSCRIBED', $attributes['subscriptions']['email']['marketing']['consent']);
    }

    public function testWithNoPropertiesGivenBehavesAsBefore(): void
    {
        $payload = $this->builder->buildListSubscription('a@example.com', 'ABC123');

        $attributes = $payload['data']['attributes']['profiles']['data'][0]['attributes'];

        self::assertSame(['email' => 'a@example.com', 'subscriptions' => [
            'email' => ['marketing' => ['consent' => 'SUBSCRIBED']],
        ]], $attributes);
    }
}
