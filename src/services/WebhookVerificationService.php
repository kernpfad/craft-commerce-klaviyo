<?php

namespace kernpfad\commerceklaviyo\services;

/**
 * Verifies Klaviyo system-webhook requests via HMAC-SHA256, per
 * https://developers.klaviyo.com/en/docs/working_with_system_webhooks
 */
class WebhookVerificationService
{
    public function verify(string $rawBody, string $signature, string $timestamp, string $secret): bool
    {
        if ($signature === '' || $timestamp === '' || $secret === '') {
            return false;
        }

        $computed = hash_hmac('sha256', $rawBody . $timestamp, $secret);

        return hash_equals($computed, $signature);
    }
}
