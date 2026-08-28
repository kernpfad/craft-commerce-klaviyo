<?php

namespace kernpfad\commerceklaviyo\services;

use GuzzleHttp\Exception\ClientException;

/**
 * Turns Klaviyo JSON:API error bodies into short, customer-safe messages
 * for synchronous form actions (back-in-stock today; reusable elsewhere).
 */
final class KlaviyoApiErrorFormatter
{
    /**
     * @param array<int|string, string> $messages optional overrides keyed by
     *     HTTP status (404, 409) or logical keys (`duplicate`, `default`)
     */
    public function formatClientException(ClientException $e, array $messages = []): string
    {
        $status = $e->getResponse()->getStatusCode();

        if (isset($messages[$status])) {
            return $messages[$status];
        }

        $body = (string)$e->getResponse()->getBody();
        $decoded = json_decode($body, true);
        $errors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $detail = strtolower((string)($error['detail'] ?? ''));
            $code = strtolower((string)($error['code'] ?? ''));

            if ($this->looksLikeDuplicateSubscription($status, $detail, $code)) {
                return $messages['duplicate']
                    ?? "You're already subscribed for this product.";
            }

            if ($status === 404 || str_contains($detail, 'not found')) {
                return $messages['404']
                    ?? $messages[404]
                    ?? "This product isn't set up for back-in-stock notifications yet. Please try again later.";
            }
        }

        return $messages['default']
            ?? "Couldn't save your subscription. Please try again.";
    }

    private function looksLikeDuplicateSubscription(int $status, string $detail, string $code): bool
    {
        if ($status === 409) {
            return true;
        }

        return str_contains($detail, 'already')
            || str_contains($detail, 'duplicate')
            || str_contains($code, 'duplicate');
    }
}
