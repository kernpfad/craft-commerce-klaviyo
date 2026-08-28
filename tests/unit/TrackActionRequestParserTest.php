<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\services\TrackActionRequestParser;
use PHPUnit\Framework\TestCase;

class TrackActionRequestParserTest extends TestCase
{
    public function testParsesEmailAndProfileProperties(): void
    {
        $parsed = (new TrackActionRequestParser())->parse([
            'email' => 'shopper@example.com',
            'profile' => [
                'first_name' => 'Ada',
                'LastLogin' => '2026-01-01',
            ],
        ]);

        self::assertSame('shopper@example.com', $parsed->email);
        self::assertSame('Ada', $parsed->profile['first_name']);
        self::assertSame('2026-01-01', $parsed->profile['LastLogin']);
    }

    public function testPrefersProfileEmailOverTopLevelEmail(): void
    {
        $parsed = (new TrackActionRequestParser())->parse([
            'email' => 'outer@example.com',
            'profile' => ['email' => 'inner@example.com'],
        ]);

        self::assertSame('inner@example.com', $parsed->email);
    }

    public function testParsesEventAndListsAndSubscribeFlag(): void
    {
        $parsed = (new TrackActionRequestParser())->parse([
            'email' => 'a@example.com',
            'event' => [
                'name' => 'Completed Survey',
                'unique_id' => 'survey-1',
                'value' => '12.5',
                'value_currency' => 'EUR',
                'Source' => 'footer',
            ],
            'list' => 'LIST1',
            'subscribe' => '1',
        ]);

        self::assertSame('Completed Survey', $parsed->eventName);
        self::assertSame('survey-1', $parsed->eventUniqueId);
        self::assertSame(12.5, $parsed->eventValue);
        self::assertSame('EUR', $parsed->eventValueCurrency);
        self::assertSame('footer', $parsed->eventProperties['Source']);
        self::assertSame(['LIST1'], $parsed->listIds);
        self::assertTrue($parsed->subscribe);
    }

    public function testParsesMultipleLists(): void
    {
        $parsed = (new TrackActionRequestParser())->parse([
            'email' => 'a@example.com',
            'lists' => ['A', '', 'B'],
        ]);

        self::assertSame(['A', 'B'], $parsed->listIds);
    }

    public function testReturnsNullEmailWhenMissingOrInvalid(): void
    {
        $parser = new TrackActionRequestParser();

        self::assertNull($parser->parse([])->email);
        self::assertNull($parser->parse(['email' => 'not-an-email'])->email);
    }
}
