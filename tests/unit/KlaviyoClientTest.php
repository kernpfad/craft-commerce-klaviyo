<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use PHPUnit\Framework\TestCase;

/**
 * Exercises KlaviyoClient's HTTP behavior against a Guzzle MockHandler —
 * genuinely unit-testable (no Craft boot, no real network call) because
 * the Guzzle client is injectable via the constructor, the same pattern
 * used for WebhookAction in the commerce-automation plugin.
 */
class KlaviyoClientTest extends TestCase
{
    public function testPostSendsTheCorrectAuthAndRevisionHeaders(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(202)], $requests);

        $client->post('events', ['data' => ['type' => 'event']]);

        self::assertCount(1, $requests);
        $request = $requests[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://a.klaviyo.com/api/events', (string)$request->getUri());
        self::assertSame('Klaviyo-API-Key test-key', $request->getHeaderLine('Authorization'));
        self::assertNotEmpty($request->getHeaderLine('revision'));
        self::assertSame('application/vnd.api+json', $request->getHeaderLine('Accept'));
        self::assertSame('application/vnd.api+json', $request->getHeaderLine('Content-Type'));
    }

    public function testUpsertFallsBackToPatchOnA409Conflict(): void
    {
        $requests = [];
        $client = $this->makeClient([
            new Response(409),
            new Response(200),
        ], $requests);

        $client->upsert('catalog-items', 'catalog-items/some-id', '42', ['data' => ['type' => 'catalog-item']]);

        self::assertCount(2, $requests);
        self::assertSame('POST', $requests[0]['request']->getMethod());
        self::assertSame('PATCH', $requests[1]['request']->getMethod());
        self::assertSame('https://a.klaviyo.com/api/catalog-items/some-id', (string)$requests[1]['request']->getUri());
    }

    public function testUpsertOnlyIncludesDataIdOnTheUpdateFallback(): void
    {
        // Klaviyo rejects `data.id` on create ("not a valid field for the
        // resource") but requires it on update, per JSON:API -- verified
        // live. The create request must go out exactly as given; the id
        // only belongs on the PATCH retry.
        $requests = [];
        $client = $this->makeClient([
            new Response(409),
            new Response(200),
        ], $requests);

        $client->upsert('catalog-items', 'catalog-items/some-id', '42', ['data' => ['type' => 'catalog-item']]);

        $createBody = json_decode((string)$requests[0]['request']->getBody(), true);
        $updateBody = json_decode((string)$requests[1]['request']->getBody(), true);

        self::assertArrayNotHasKey('id', $createBody['data']);
        self::assertSame('$custom:::$default:::42', $updateBody['data']['id']);
    }

    public function testUpsertStripsCreateOnlyAttributesFromTheUpdateFallback(): void
    {
        // 'external_id', 'integration_type', and 'catalog_type' are all
        // rejected by Klaviyo on update ("not a valid field for the
        // resource") -- verified live. The create request keeps them; the
        // PATCH retry must not resend them.
        $requests = [];
        $client = $this->makeClient([
            new Response(409),
            new Response(200),
        ], $requests);

        $client->upsert('catalog-items', 'catalog-items/some-id', '42', [
            'data' => [
                'type' => 'catalog-item',
                'attributes' => [
                    'external_id' => '42',
                    'title' => 'A Shirt',
                    'integration_type' => '$custom',
                    'catalog_type' => '$default',
                ],
            ],
        ]);

        $createBody = json_decode((string)$requests[0]['request']->getBody(), true);
        $updateBody = json_decode((string)$requests[1]['request']->getBody(), true);

        self::assertSame(['external_id', 'title', 'integration_type', 'catalog_type'], array_keys($createBody['data']['attributes']));
        self::assertSame(['title' => 'A Shirt'], $updateBody['data']['attributes']);
    }

    public function testUpsertDropsTheVariantItemRelationshipFromTheUpdateFallback(): void
    {
        // Klaviyo rejects a catalog-variant's `relationships.item` on
        // update: "'item' is not an allowed relation on the
        // catalog-variants resource" -- verified live. It's only settable
        // at creation.
        $requests = [];
        $client = $this->makeClient([
            new Response(409),
            new Response(200),
        ], $requests);

        $client->upsert('catalog-variants', 'catalog-variants/some-id', '1-md', [
            'data' => [
                'type' => 'catalog-variant',
                'attributes' => ['title' => 'Medium'],
                'relationships' => [
                    'item' => ['data' => ['type' => 'catalog-item', 'id' => '$custom:::$default:::1']],
                ],
            ],
        ]);

        $createBody = json_decode((string)$requests[0]['request']->getBody(), true);
        $updateBody = json_decode((string)$requests[1]['request']->getBody(), true);

        self::assertArrayHasKey('relationships', $createBody['data']);
        self::assertArrayNotHasKey('relationships', $updateBody['data']);
    }

    public function testUpsertDoesNotFallBackOnANonConflictError(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(500)], $requests);

        $this->expectException(\GuzzleHttp\Exception\ServerException::class);
        $client->upsert('catalog-items', 'catalog-items/some-id', '42', ['data' => []]);
    }

    public function testDeleteTreatsA404AsSuccessRatherThanAnError(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(404)], $requests);

        $client->delete('catalog-items/already-gone');

        self::assertCount(1, $requests);
    }

    public function testDeletePropagatesOtherErrors(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(500)], $requests);

        $this->expectException(\GuzzleHttp\Exception\ServerException::class);
        $client->delete('catalog-items/some-id');
    }

    public function testAddRelationshipTreatsA409AsSuccessRatherThanAnError(): void
    {
        // Klaviyo returns 409 when the relationship member already exists
        // -- exactly the state addRelationship() is trying to reach, not a
        // real conflict. Verified live: this failed a whole catalog sync
        // job every time a product's categories were already linked from
        // a previous sync.
        $requests = [];
        $client = $this->makeClient([new Response(409)], $requests);

        $client->addRelationship('catalog-items/some-id/relationships/categories', ['data' => []]);

        self::assertCount(1, $requests);
    }

    public function testAddRelationshipPropagatesOtherErrors(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(500)], $requests);

        $this->expectException(\GuzzleHttp\Exception\ServerException::class);
        $client->addRelationship('catalog-items/some-id/relationships/categories', ['data' => []]);
    }

    public function testRemoveRelationshipTreats404And409AsSuccess(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(404)], $requests);

        $client->removeRelationship('catalog-items/some-id/relationships/categories', ['data' => []]);

        self::assertCount(1, $requests);
        self::assertSame('DELETE', $requests[0]['request']->getMethod());
    }

    public function testPostReturningDecodesTheResponseBody(): void
    {
        $requests = [];
        $client = $this->makeClient([
            new Response(202, [], '{"data":{"type":"catalog-item-bulk-create-job","id":"job-1"}}'),
        ], $requests);

        $result = $client->postReturning('catalog-item-bulk-create-jobs', ['data' => []]);

        self::assertSame('job-1', $result['data']['id']);
    }

    public function testPollBulkJobReturnsWhenStatusIsComplete(): void
    {
        $requests = [];
        $client = $this->makeClient([
            new Response(200, [], '{"data":{"id":"job-1","attributes":{"status":"complete","total_count":2,"completed_count":2,"failed_count":0,"errors":[]}}}'),
        ], $requests);

        $result = $client->pollBulkJob('catalog-item-bulk-create-jobs/job-1');

        self::assertSame('complete', $result['status']);
        self::assertSame(2, $result['completedCount']);
    }

    public function testGetSendsTheCorrectAuthHeaderAndDecodesTheResponse(): void
    {
        $requests = [];
        $client = $this->makeClient(
            [new Response(200, ['Content-Type' => 'application/json'], '{"data":{"type":"account"}}')],
            $requests,
        );

        $result = $client->get('accounts/');

        self::assertCount(1, $requests);
        $request = $requests[0]['request'];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://a.klaviyo.com/api/accounts/', (string)$request->getUri());
        self::assertSame('Klaviyo-API-Key test-key', $request->getHeaderLine('Authorization'));
        self::assertSame(['data' => ['type' => 'account']], $result);
    }

    public function testGetPropagatesErrors(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(401)], $requests);

        $this->expectException(\GuzzleHttp\Exception\ClientException::class);
        $client->get('accounts/');
    }

    /**
     * @param Response[] $responses
     * @param array<int, array{request: \Psr\Http\Message\RequestInterface}> $requests
     */
    private function makeClient(array $responses, array &$requests): KlaviyoClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($requests));
        $guzzle = new Client(['handler' => $stack]);

        return new KlaviyoClient('test-key', $guzzle);
    }
}
