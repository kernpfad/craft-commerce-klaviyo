<?php

namespace kernpfad\commerceklaviyo\jobs;

use Craft;
use craft\queue\BaseJob;
use GuzzleHttp\Exception\RequestException;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;

/**
 * Shared execution wrapper for every queue job that talks to Klaviyo.
 * Resolves the client once, skips gracefully when no API key is
 * configured, and ensures failures are logged before rethrowing so
 * Craft's queue can retry without surfacing errors to customers.
 */
abstract class BaseKlaviyoJob extends BaseJob
{
    /**
     * @param callable(KlaviyoClient): void $callback
     */
    protected function withKlaviyoClient(callable $callback): void
    {
        $client = CommerceKlaviyo::getInstance()?->getKlaviyoClient();

        if ($client === null) {
            Craft::warning($this->skippedMessage(), __METHOD__);

            return;
        }

        try {
            $callback($client);
            $this->onSuccess();
        } catch (\Throwable $e) {
            $e = $this->withFullResponseBody($e);

            Craft::error($this->errorMessage($e), __METHOD__);
            $this->recordFailure($e);

            throw $e;
        }
    }

    /**
     * Guzzle's own exception message truncates the response body preview
     * (Klaviyo's actual validation `detail` — the one piece of information
     * that says what's actually wrong with a payload — routinely lands
     * past that cutoff and shows up as "(truncated...)" everywhere this
     * message ends up: the queue log, and the CP's "Last catalog sync
     * error" notice). The body stream is still fully readable at this
     * point — Guzzle rewinds it after building that truncated summary
     * specifically so callers can still do this.
     *
     * Only replaces the exception for logging/display; this runs after
     * {@see KlaviyoClient::upsert()}'s own 409-vs-real-error branching has
     * already resolved, so nothing upstream is still doing type-based
     * handling on it.
     */
    private function withFullResponseBody(\Throwable $e): \Throwable
    {
        if (!$e instanceof RequestException) {
            return $e;
        }

        $response = $e->getResponse();

        if ($response === null) {
            return $e;
        }

        $body = (string)$response->getBody();

        if ($body === '') {
            return $e;
        }

        $request = $e->getRequest();

        return new \RuntimeException(
            sprintf(
                '%s %s resulted in a %d %s response: %s',
                $request->getMethod(),
                $request->getUri(),
                $response->getStatusCode(),
                $response->getReasonPhrase(),
                $body,
            ),
            previous: $e,
        );
    }

    protected function recordFailure(\Throwable $e): void
    {
        $category = $this->failureCategory();

        if ($category === null) {
            return;
        }

        $status = new KlaviyoStatusService();

        match ($category) {
            KlaviyoStatusService::CATEGORY_CATALOG => $status->recordCatalogError($e->getMessage()),
            KlaviyoStatusService::CATEGORY_TRACK => $status->recordTrackError($e->getMessage()),
            default => null,
        };
    }

    protected function onSuccess(): void
    {
        if ($this->failureCategory() === KlaviyoStatusService::CATEGORY_CATALOG) {
            (new KlaviyoStatusService())->recordCatalogSuccess();
        }
    }

    /**
     * Which settings-screen error bucket a failure from this job belongs in.
     * Return null when failures should not update the CP status display.
     */
    protected function failureCategory(): ?string
    {
        return null;
    }

    abstract protected function skippedMessage(): string;

    abstract protected function errorMessage(\Throwable $e): string;
}
