<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoListsService;
use PHPUnit\Framework\TestCase;
use yii\caching\ArrayCache;

class KlaviyoListsServiceTest extends TestCase
{
    public function testParsesListNamesAndOptInProcess(): void
    {
        $client = $this->createMock(KlaviyoClient::class);
        $client->method('get')->willReturn([
            'data' => [[
                'type' => 'list',
                'id' => 'LIST123',
                'attributes' => [
                    'name' => 'Newsletter',
                    'opt_in_process' => 'double_opt_in',
                ],
            ]],
            'links' => [],
        ]);

        $lists = (new KlaviyoListsService($client, new ArrayCache()))->getLists();

        self::assertSame([
            [
                'id' => 'LIST123',
                'name' => 'Newsletter',
                'optInProcess' => 'double_opt_in',
            ],
        ], $lists);
    }

    public function testReturnsEmptyListWhenClientIsUnavailable(): void
    {
        self::assertSame([], (new KlaviyoListsService())->getLists());
    }
}
