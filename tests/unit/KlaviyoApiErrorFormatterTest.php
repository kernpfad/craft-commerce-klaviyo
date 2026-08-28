<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use kernpfad\commerceklaviyo\services\KlaviyoApiErrorFormatter;
use PHPUnit\Framework\TestCase;

class KlaviyoApiErrorFormatterTest extends TestCase
{
    private KlaviyoApiErrorFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new KlaviyoApiErrorFormatter();
    }

    public function testUsesExplicitStatusMessageWhenProvided(): void
    {
        $exception = $this->clientException(404, ['errors' => []]);

        self::assertSame(
            'Custom not found.',
            $this->formatter->formatClientException($exception, [404 => 'Custom not found.'])
        );
    }

    public function testMaps409ToAlreadySubscribedMessage(): void
    {
        $exception = $this->clientException(409, [
            'errors' => [
                ['detail' => 'Conflict', 'code' => 'duplicate'],
            ],
        ]);

        self::assertSame(
            "You're already subscribed for this product.",
            $this->formatter->formatClientException($exception)
        );
    }

    public function testMapsDuplicateDetailOn400(): void
    {
        $exception = $this->clientException(400, [
            'errors' => [
                ['detail' => 'A subscription already exists for this profile', 'code' => 'invalid'],
            ],
        ]);

        self::assertSame(
            "You're already subscribed for this product.",
            $this->formatter->formatClientException($exception)
        );
    }

    public function testMaps404ToCatalogSetupMessage(): void
    {
        $exception = $this->clientException(404, [
            'errors' => [
                ['detail' => 'Catalog variant not found', 'code' => 'not_found'],
            ],
        ]);

        self::assertSame(
            "This product isn't set up for back-in-stock notifications yet. Please try again later.",
            $this->formatter->formatClientException($exception)
        );
    }

    public function testFallsBackToDefaultMessage(): void
    {
        $exception = $this->clientException(500, [
            'errors' => [
                ['detail' => 'Something broke', 'code' => 'server_error'],
            ],
        ]);

        self::assertSame(
            "Couldn't save your subscription. Please try again.",
            $this->formatter->formatClientException($exception)
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function clientException(int $status, array $body): ClientException
    {
        return new ClientException(
            'Klaviyo error',
            new Request('POST', 'https://a.klaviyo.com/api/back-in-stock-subscriptions'),
            new Response($status, [], (string)json_encode($body)),
        );
    }
}
