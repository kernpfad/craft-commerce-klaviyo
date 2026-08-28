<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\services\WebhookPayloadParser;
use PHPUnit\Framework\TestCase;

class WebhookPayloadParserTest extends TestCase
{
    private WebhookPayloadParser $parser;

    protected function setUp(): void
    {
        $this->parser = new WebhookPayloadParser();
    }

    public function testParsesUnsubscribeEventsFromIncludedProfileEmail(): void
    {
        $changes = $this->parser->parseConsentChanges([
            'data' => [[
                'topic' => 'event:klaviyo.unsubscribed_from_email_marketing',
                'payload' => [
                    'included' => [[
                        'type' => 'profile',
                        'attributes' => ['email' => 'shopper@example.com'],
                    ]],
                ],
            ]],
        ]);

        self::assertSame([
            ['email' => 'shopper@example.com', 'optedOut' => true],
        ], $changes);
    }

    public function testParsesResubscribeEvents(): void
    {
        $changes = $this->parser->parseConsentChanges([
            'data' => [[
                'topic' => 'event:klaviyo.subscribed_to_email_marketing',
                'payload' => [
                    'data' => [
                        'attributes' => [
                            'event_properties' => ['email' => 'back@example.com'],
                        ],
                    ],
                ],
            ]],
        ]);

        self::assertSame([
            ['email' => 'back@example.com', 'optedOut' => false],
        ], $changes);
    }

    public function testIgnoresUnrelatedTopics(): void
    {
        self::assertSame([], $this->parser->parseConsentChanges([
            'data' => [[
                'topic' => 'event:klaviyo.opened_email',
                'payload' => [
                    'included' => [[
                        'type' => 'profile',
                        'attributes' => ['email' => 'shopper@example.com'],
                    ]],
                ],
            ]],
        ]));
    }
}
