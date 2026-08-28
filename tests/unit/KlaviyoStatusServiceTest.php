<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\services\KlaviyoStatusService;
use PHPUnit\Framework\TestCase;
use yii\caching\ArrayCache;

class KlaviyoStatusServiceTest extends TestCase
{
    public function testRecordsAndRetrievesCatalogAndTrackErrors(): void
    {
        $cache = new ArrayCache();
        $service = new KlaviyoStatusService($cache);

        self::assertNull($service->getCatalogError());
        self::assertNull($service->getTrackError());

        $service->recordCatalogError('Catalog upsert failed');
        $service->recordTrackError('Event rejected');

        $catalogError = $service->getCatalogError();
        $trackError = $service->getTrackError();

        self::assertNotNull($catalogError);
        self::assertSame('Catalog upsert failed', $catalogError['message']);

        self::assertNotNull($trackError);
        self::assertSame('Event rejected', $trackError['message']);
    }

    public function testClearCatalogErrorRemovesOnlyTheCatalogError(): void
    {
        $cache = new ArrayCache();
        $service = new KlaviyoStatusService($cache);

        $service->recordCatalogError('Catalog upsert failed');
        $service->recordTrackError('Event rejected');

        $service->clearCatalogError();

        self::assertNull($service->getCatalogError());
        self::assertNotNull($service->getTrackError());
    }

    public function testClearTrackErrorRemovesOnlyTheTrackError(): void
    {
        $cache = new ArrayCache();
        $service = new KlaviyoStatusService($cache);

        $service->recordCatalogError('Catalog upsert failed');
        $service->recordTrackError('Event rejected');

        $service->clearTrackError();

        self::assertNotNull($service->getCatalogError());
        self::assertNull($service->getTrackError());
    }

    public function testClearingAnErrorThatWasNeverRecordedIsANoop(): void
    {
        $cache = new ArrayCache();
        $service = new KlaviyoStatusService($cache);

        $service->clearCatalogError();

        self::assertNull($service->getCatalogError());
    }

    public function testRecordsCatalogSuccessReindexAndBulkJobSummaries(): void
    {
        $cache = new ArrayCache();
        $service = new KlaviyoStatusService($cache);

        $service->recordCatalogSuccess();
        $service->recordReindex([
            'productCount' => 10,
            'variantCount' => 25,
            'itemJobCount' => 1,
            'variantJobCount' => 1,
            'mode' => 'bulk',
        ]);
        $service->recordBulkJob([
            'type' => 'items',
            'jobId' => 'job-123',
            'status' => 'complete',
            'totalCount' => 10,
            'completedCount' => 10,
            'failedCount' => 0,
        ]);

        self::assertNotNull($service->getLastCatalogSuccess());

        $reindex = $service->getLastReindex();
        self::assertNotNull($reindex);
        self::assertSame(10, $reindex['productCount']);
        self::assertSame('bulk', $reindex['mode']);

        $bulkJob = $service->getLastBulkJob();
        self::assertNotNull($bulkJob);
        self::assertSame('job-123', $bulkJob['jobId']);
    }
}
