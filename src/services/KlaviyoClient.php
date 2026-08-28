<?php

namespace kernpfad\commerceklaviyo\services;

use Craft;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * A thin, direct REST wrapper around Klaviyo's API — deliberately not built
 * on the official `klaviyo/api` SDK. That SDK's `createEvent()`/
 * `createCatalogItem()`/etc. expect fully-constructed typed Model objects
 * (verified against the installed SDK source: `EventsApi::createEvent()`
 * type-hints `\KlaviyoAPI\Model\EventCreateQueryV2`, not an array), which
 * would mean re-deriving a large nested object graph for every payload.
 * Klaviyo's REST API itself is a stable, well-documented plain JSON:API
 * surface (verified against developers.klaviyo.com and the published
 * OpenAPI schema), so sending the exact same JSON body directly is simpler
 * and just as correct — and keeps payload construction in this plugin's
 * own, fully unit-tested {@see EventPayloadBuilder}/{@see CatalogPayloadBuilder}.
 *
 * Every call here can throw (network failure, 4xx/5xx from Klaviyo) — by
 * design. Call sites (the queue jobs) are responsible for catching and
 * logging, never the code that queues them, so a Klaviyo outage can never
 * propagate into a customer-facing request.
 */
class KlaviyoClient
{
    private const BASE_URL = 'https://a.klaviyo.com/api/';
    private const REVISION = '2026-07-15';

    /**
     * Set explicitly because `Craft::createGuzzleClient()` configures only a
     * User-Agent and an optional proxy (verified against Craft's own
     * source) — without these, the client inherits Guzzle's default of *no
     * timeout at all*. That matters most for
     * {@see \kernpfad\commerceklaviyo\controllers\SubscriptionsController},
     * which calls Klaviyo synchronously from a public, anonymously
     * accessible endpoint: an unresponsive Klaviyo would otherwise hold
     * those requests open until PHP's execution limit, tying up a
     * request worker per hit. On the queue side a hang would instead
     * block a queue worker, which is less visible but no more desirable.
     */
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TOTAL_TIMEOUT_SECONDS = 15;
    private const BULK_POLL_INTERVAL_SECONDS = 3;

    /**
     * 200 attempts * 3s = 600s (10 minutes). Verified live: a
     * catalog-item-bulk-update-job for just 6 items took ~5.6 minutes to
     * report `complete` — the previous budget (90 * 2s = 180s) gave up on
     * a job that was still processing normally and had zero actual
     * problem, throwing "did not complete in time" for what was really
     * just Klaviyo's bulk pipeline taking longer than assumed. This runs
     * from a queue job, not a user-facing request, so a long wait here
     * costs a queue worker, not a blocked page load.
     */
    private const BULK_POLL_MAX_ATTEMPTS = 200;

    public function __construct(
        private readonly string $privateApiKey,
        private ?ClientInterface $httpClient = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path): array
    {
        $response = $this->getHttpClient()->request('GET', self::BASE_URL . ltrim($path, '/'), [
            'headers' => $this->headers(),
        ]);

        $decoded = json_decode((string)$response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function post(string $path, array $payload): void
    {
        $this->request('POST', $path, $payload);
    }

    /**
     * Like {@see post()}, but returns the decoded JSON body — needed for
     * bulk catalog jobs where the response carries the async job id.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function postReturning(string $path, array $payload): array
    {
        return $this->requestReturning('POST', $path, $payload);
    }

    /**
     * Polls a Klaviyo bulk catalog job until it reaches a terminal status.
     *
     * @return array{jobId: string, status: string, totalCount: int, completedCount: int, failedCount: int, errors: array<int, array<string, mixed>>}
     */
    public function pollBulkJob(string $jobPath): array
    {
        for ($attempt = 0; $attempt < self::BULK_POLL_MAX_ATTEMPTS; $attempt++) {
            $response = $this->get($jobPath);
            $attributes = $response['data']['attributes'] ?? [];
            $status = (string)($attributes['status'] ?? '');

            if (in_array($status, ['complete', 'cancelled'], true)) {
                return [
                    'jobId' => (string)($response['data']['id'] ?? ''),
                    'status' => $status,
                    'totalCount' => (int)($attributes['total_count'] ?? 0),
                    'completedCount' => (int)($attributes['completed_count'] ?? 0),
                    'failedCount' => (int)($attributes['failed_count'] ?? 0),
                    'errors' => is_array($attributes['errors'] ?? null) ? $attributes['errors'] : [],
                ];
            }

            sleep(self::BULK_POLL_INTERVAL_SECONDS);
        }

        throw new \RuntimeException("Bulk job \"{$jobPath}\" did not complete in time.");
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function patch(string $path, array $payload): void
    {
        $this->request('PATCH', $path, $payload);
    }

    /**
     * Creates the resource at `$createPath`, falling back to a PATCH at
     * `$updatePath` when Klaviyo rejects the create because a resource with
     * that external ID already exists (a 409 Conflict). Catalog items and
     * variants have no dedicated "upsert" endpoint, so every real-time save
     * (a product/variant might already exist in Klaviyo from a previous
     * save) needs this create-then-fall-back-to-update dance.
     *
     * `$payload` is shaped for CREATE; the PATCH fallback derives its own,
     * more restrictive shape from it via {@see toUpdatePayload()} rather
     * than resending it verbatim — see that method for exactly what
     * differs and why (all verified against real 400s, not docs).
     *
     * @param array<string, mixed> $payload
     */
    public function upsert(string $createPath, string $updatePath, string $externalId, array $payload): void
    {
        try {
            $this->post($createPath, $payload);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 409) {
                throw $e;
            }

            $this->patch($updatePath, $this->toUpdatePayload($externalId, $payload));
        }
    }

    /**
     * Klaviyo's catalog-item/catalog-variant PATCH schema is stricter than
     * POST's — verified live, not from docs:
     *
     * - `data.id` is required on update (JSON:API), but rejected on create
     *   with "'id' is not a valid field for the resource".
     * - `external_id`, `integration_type`, and `catalog_type` are
     *   create-only/immutable attributes, each rejected on update with
     *   "'<field>' is not a valid field for the resource" — they're
     *   already permanently encoded in the resource's composite id by the
     *   time an update is possible.
     * - A catalog-variant's `relationships.item` link (to its parent
     *   catalog-item) is create-only too, rejected on update with "'item'
     *   is not an allowed relation on the catalog-variants resource" — the
     *   variant is already linked from when it was created.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function toUpdatePayload(string $externalId, array $payload): array
    {
        unset(
            $payload['data']['attributes']['external_id'],
            $payload['data']['attributes']['integration_type'],
            $payload['data']['attributes']['catalog_type'],
            $payload['data']['relationships'],
        );

        $payload['data']['id'] = CatalogPayloadBuilder::compositeId($externalId);

        return $payload;
    }

    /**
     * A 404 is treated as success: the resource is already gone, which is
     * exactly what a delete is trying to achieve.
     */
    public function delete(string $path): void
    {
        try {
            $this->getHttpClient()->request('DELETE', self::BASE_URL . ltrim($path, '/'), [
                'headers' => $this->headers(),
            ]);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * Adds a member to a to-many relationship (e.g.
     * `catalog-items/{id}/relationships/categories`) — same idempotency
     * treatment as {@see delete()}'s 404, mirrored for the opposite case:
     * a 409 here is Klaviyo saying the relationship already exists, which
     * is exactly the state this call is trying to reach, not a real
     * conflict. Verified live: re-syncing an item whose categories were
     * already attached from a previous sync got a genuine 409 every time
     * after the first, failing the whole job over what's actually a no-op.
     *
     * @param array<string, mixed> $payload
     */
    public function addRelationship(string $path, array $payload): void
    {
        try {
            $this->post($path, $payload);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 409) {
                throw $e;
            }
        }
    }

    /**
     * Removes members from a to-many relationship (e.g. stale catalog
     * categories on an item). A 404 means the relationship or member is
     * already gone; a 409 means it was already detached — both are success
     * for sync purposes, mirroring {@see addRelationship()}'s 409 handling.
     *
     * @param array<string, mixed> $payload
     */
    public function removeRelationship(string $path, array $payload): void
    {
        try {
            $this->getHttpClient()->request('DELETE', self::BASE_URL . ltrim($path, '/'), [
                'json' => $payload,
                'headers' => $this->headers(),
            ]);
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();

            if (in_array($status, [404, 409], true)) {
                return;
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @throws GuzzleException
     */
    private function request(string $method, string $path, array $payload): void
    {
        $this->requestReturning($method, $path, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws GuzzleException
     */
    private function requestReturning(string $method, string $path, array $payload): array
    {
        $response = $this->getHttpClient()->request($method, self::BASE_URL . ltrim($path, '/'), [
            'json' => $payload,
            'headers' => $this->headers(),
        ]);

        $decoded = json_decode((string)$response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Klaviyo-API-Key ' . $this->privateApiKey,
            'revision' => self::REVISION,
            // Klaviyo's Catalogs/Events APIs are JSON:API; Prefer their
            // documented media type over bare application/json (still
            // accepted by many endpoints, but not what the OpenAPI
            // schemas advertise).
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => 'application/vnd.api+json',
        ];
    }

    private function getHttpClient(): ClientInterface
    {
        return $this->httpClient ??= Craft::createGuzzleClient([
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::TOTAL_TIMEOUT_SECONDS,
        ]);
    }
}
