<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\services\WebhookVerificationService;
use PHPUnit\Framework\TestCase;

class WebhookVerificationServiceTest extends TestCase
{
    public function testVerifyAcceptsValidSignature(): void
    {
        $body = '{"data":[]}';
        $timestamp = 'Thu, 04 Jan 2024 18:05:25 GMT';
        $secret = 'test-secret-key-1234';
        $signature = hash_hmac('sha256', $body . $timestamp, $secret);

        self::assertTrue((new WebhookVerificationService())->verify($body, $signature, $timestamp, $secret));
    }

    public function testVerifyRejectsInvalidSignature(): void
    {
        self::assertFalse((new WebhookVerificationService())->verify('{}', 'bad-signature', 'now', 'secret'));
    }
}
