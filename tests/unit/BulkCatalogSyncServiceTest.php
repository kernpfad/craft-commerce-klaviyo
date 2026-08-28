<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use kernpfad\commerceklaviyo\services\BulkCatalogSyncService;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use PHPUnit\Framework\TestCase;

class BulkCatalogSyncServiceTest extends TestCase
{
    public function testBulkUpsertItemsSendsEachEntryAsTheInnerResourceObjectNotADoubleWrappedEnvelope(): void
    {
        // Each entry's `payload` is a full single-create `{data: {type,
        // attributes}}` envelope (what KlaviyoClient::upsert() needs for
        // the real-time path) -- nesting that whole envelope into
        // items.data[] again left `type` one level deeper than Klaviyo
        // expects there, and it rejected every real bulk job with "'type'
        // is a required field for the resource 'request-data'" as a
        // result. This asserts items.data[] holds the resource itself.
        $requests = [];
        $client = $this->makeClient([
            new Response(202, [], (string)json_encode(['data' => ['id' => 'job-1']])),
            new Response(200, [], (string)json_encode([
                'data' => [
                    'id' => 'job-1',
                    'attributes' => [
                        'status' => 'complete',
                        'total_count' => 1,
                        'completed_count' => 1,
                        'failed_count' => 0,
                        'errors' => [],
                    ],
                ],
            ])),
        ], $requests);

        $service = new BulkCatalogSyncService($client);

        $service->bulkUpsertItems([
            [
                'externalId' => '1',
                'payload' => [
                    'data' => [
                        'type' => 'catalog-item',
                        'attributes' => ['external_id' => '1', 'title' => 'A Shirt'],
                    ],
                ],
            ],
        ]);

        $body = json_decode((string)$requests[0]['request']->getBody(), true);
        $item = $body['data']['attributes']['items']['data'][0];

        self::assertSame('catalog-item', $item['type']);
        self::assertSame('A Shirt', $item['attributes']['title']);
        self::assertArrayNotHasKey('data', $item);
    }

    public function testBulkUpsertItemsRetriesDuplicatesThroughTheUpdateEndpointAsInnerResourceObjectsToo(): void
    {
        $requests = [];
        $client = $this->makeClient([
            new Response(202, [], (string)json_encode(['data' => ['id' => 'create-job']])),
            new Response(200, [], (string)json_encode([
                'data' => [
                    'id' => 'create-job',
                    'attributes' => [
                        'status' => 'complete',
                        'total_count' => 1,
                        'completed_count' => 0,
                        'failed_count' => 1,
                        'errors' => [[
                            'meta' => ['external_id' => '1'],
                            'detail' => 'An item with the external id `1` already exists.',
                        ]],
                    ],
                ],
            ])),
            new Response(202, [], (string)json_encode(['data' => ['id' => 'update-job']])),
            new Response(200, [], (string)json_encode([
                'data' => [
                    'id' => 'update-job',
                    'attributes' => [
                        'status' => 'complete',
                        'total_count' => 1,
                        'completed_count' => 1,
                        'failed_count' => 0,
                        'errors' => [],
                    ],
                ],
            ])),
        ], $requests);

        $service = new BulkCatalogSyncService($client);

        $service->bulkUpsertItems([
            [
                'externalId' => '1',
                'payload' => [
                    'data' => [
                        'type' => 'catalog-item',
                        'attributes' => [
                            'external_id' => '1',
                            'title' => 'A Shirt',
                            'integration_type' => '$custom',
                            'catalog_type' => '$default',
                        ],
                    ],
                ],
            ],
        ]);

        $updateBody = json_decode((string)$requests[2]['request']->getBody(), true);
        $item = $updateBody['data']['attributes']['items']['data'][0];

        self::assertSame('catalog-item', $item['type']);
        self::assertSame('$custom:::$default:::1', $item['id']);
        self::assertSame('A Shirt', $item['attributes']['title']);
        self::assertArrayNotHasKey('external_id', $item['attributes']);
        self::assertArrayNotHasKey('data', $item);
    }

    public function testExtractDuplicateExternalIdsFindsAlreadyExistsErrors(): void
    {
        $service = new BulkCatalogSyncService(new KlaviyoClient('test'));

        $externalIds = $service->extractDuplicateExternalIds([
            [
                'meta' => ['external_id' => '123'],
                'detail' => 'An item with the external id `123` already exists.',
            ],
            [
                'meta' => ['external_id' => '456'],
                'detail' => 'Invalid category information.',
            ],
        ]);

        self::assertSame(['123'], $externalIds);
    }

    public function testBulkUpsertItemsReturnsCompleteImmediatelyForAnEmptyBatch(): void
    {
        $service = new BulkCatalogSyncService(new KlaviyoClient('test'));

        self::assertSame([
            'jobId' => '',
            'status' => 'complete',
            'totalCount' => 0,
            'completedCount' => 0,
            'failedCount' => 0,
        ], $service->bulkUpsertItems([]));
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
