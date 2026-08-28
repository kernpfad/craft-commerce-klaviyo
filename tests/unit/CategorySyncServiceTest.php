<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use kernpfad\commerceklaviyo\services\CategorySyncService;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use PHPUnit\Framework\TestCase;

/**
 * Exercises CategorySyncService against a Guzzle MockHandler, the same
 * technique KlaviyoClientTest uses — genuinely unit-testable since
 * KlaviyoClient takes its Guzzle client via the constructor.
 */
class CategorySyncServiceTest extends TestCase
{
    public function testSyncsEachCategoryThenAttachesOnlyNewRelationships(): void
    {
        $requests = [];
        $client = $this->makeClient([
            new Response(201), // create category 3
            new Response(201), // create category 9
            new Response(200, [], '{"data":[{"type":"catalog-category","id":"$custom:::$default:::3"}]}'), // fetch existing links
            new Response(200), // attach category 9 only
        ], $requests);

        (new CategorySyncService($client))->syncItemCategories('42', [
            ['id' => '3', 'name' => 'Shirts'],
            ['id' => '9', 'name' => 'Sale'],
        ]);

        self::assertCount(4, $requests);

        self::assertSame('POST', $requests[0]['request']->getMethod());
        self::assertSame('https://a.klaviyo.com/api/catalog-categories', (string)$requests[0]['request']->getUri());
        self::assertSame('POST', $requests[1]['request']->getMethod());
        self::assertSame('https://a.klaviyo.com/api/catalog-categories', (string)$requests[1]['request']->getUri());

        $fetchRequest = $requests[2]['request'];
        self::assertSame('GET', $fetchRequest->getMethod());
        self::assertSame(
            'https://a.klaviyo.com/api/catalog-items/$custom:::$default:::42/relationships/categories',
            (string)$fetchRequest->getUri()
        );

        $relationshipsRequest = $requests[3]['request'];
        self::assertSame('POST', $relationshipsRequest->getMethod());
        self::assertSame(
            'https://a.klaviyo.com/api/catalog-items/$custom:::$default:::42/relationships/categories',
            (string)$relationshipsRequest->getUri()
        );

        $relationshipsBody = json_decode((string)$relationshipsRequest->getBody(), true);
        self::assertSame([
            ['type' => 'catalog-category', 'id' => '$custom:::$default:::9'],
        ], $relationshipsBody['data']);
    }

    public function testUpsertsACategoryViaCreateThenFallsBackToPatchOnA409(): void
    {
        $requests = [];
        $client = $this->makeClient([
            new Response(409), // category 3 already exists
            new Response(200), // update it
            new Response(200, [], '{"data":[]}'),
            new Response(200), // attach relationships
        ], $requests);

        (new CategorySyncService($client))->syncItemCategories('42', [
            ['id' => '3', 'name' => 'Shirts'],
        ]);

        self::assertCount(4, $requests);
        self::assertSame('POST', $requests[0]['request']->getMethod());
        self::assertSame('PATCH', $requests[1]['request']->getMethod());
        self::assertSame(
            'https://a.klaviyo.com/api/catalog-categories/$custom:::$default:::3',
            (string)$requests[1]['request']->getUri()
        );
    }

    public function testUnlinksCategoriesRemovedInCraft(): void
    {
        $requests = [];
        $client = $this->makeClient([
            new Response(201), // upsert category 3
            new Response(200, [], '{"data":[{"type":"catalog-category","id":"$custom:::$default:::3"},{"type":"catalog-category","id":"$custom:::$default:::9"}]}'),
            new Response(204), // detach category 9
        ], $requests);

        (new CategorySyncService($client))->syncItemCategories('42', [
            ['id' => '3', 'name' => 'Shirts'],
        ]);

        self::assertCount(3, $requests);
        self::assertSame('POST', $requests[0]['request']->getMethod());
        self::assertSame('GET', $requests[1]['request']->getMethod());
        self::assertSame('DELETE', $requests[2]['request']->getMethod());

        $detachBody = json_decode((string)$requests[2]['request']->getBody(), true);
        self::assertSame([
            ['type' => 'catalog-category', 'id' => '$custom:::$default:::9'],
        ], $detachBody['data']);
    }

    public function testRelinkingAlreadyAttachedCategoriesOnAReSyncIsNotAFailure(): void
    {
        $requests = [];
        $client = $this->makeClient([
            new Response(409), // category 3 already exists -> update path
            new Response(200),
            new Response(200, [], '{"data":[{"type":"catalog-category","id":"$custom:::$default:::3"}]}'),
        ], $requests);

        (new CategorySyncService($client))->syncItemCategories('42', [
            ['id' => '3', 'name' => 'Shirts'],
        ]);

        self::assertCount(3, $requests);
    }

    public function testDoesNothingWhenThereAreNoCategories(): void
    {
        $requests = [];
        $client = $this->makeClient([
            new Response(200, [], '{"data":[]}'),
        ], $requests);

        (new CategorySyncService($client))->syncItemCategories('42', []);

        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0]['request']->getMethod());
    }

    public function testExternalIdFromCompositeIdReturnsTheTrailingSegment(): void
    {
        self::assertSame('42', CategorySyncService::externalIdFromCompositeId('$custom:::$default:::42'));
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
