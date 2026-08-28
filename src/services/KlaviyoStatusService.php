<?php

namespace kernpfad\commerceklaviyo\services;

use Craft;
use yii\caching\CacheInterface;

/**
 * Records the most recent Klaviyo catalog-sync and event-track failures
 * for display on the plugin settings screen. Stored in Craft's cache (not
 * project config) so transient API errors never pollute version-controlled
 * settings.
 */
class KlaviyoStatusService
{
    public const CATEGORY_CATALOG = 'catalog';
    public const CATEGORY_TRACK = 'track';

    private const CACHE_KEY_PREFIX = 'commerce-klaviyo:last-error:';
    private const CACHE_KEY_LAST_CATALOG_SUCCESS = 'commerce-klaviyo:last-catalog-success';
    private const CACHE_KEY_LAST_REINDEX = 'commerce-klaviyo:last-reindex';
    private const CACHE_KEY_LAST_BULK_JOB = 'commerce-klaviyo:last-bulk-job';
    private const CACHE_DURATION = 2592000; // 30 days

    public function __construct(
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    public function recordCatalogError(string $message): void
    {
        $this->record(self::CATEGORY_CATALOG, $message);
    }

    public function recordTrackError(string $message): void
    {
        $this->record(self::CATEGORY_TRACK, $message);
    }

    public function recordCatalogSuccess(): void
    {
        $this->write(self::CACHE_KEY_LAST_CATALOG_SUCCESS, ['at' => time()]);
    }

    /**
     * @param array{productCount: int, variantCount: int, itemJobCount: int, variantJobCount: int, mode: string} $stats
     */
    public function recordReindex(array $stats): void
    {
        $this->write(self::CACHE_KEY_LAST_REINDEX, $stats + ['at' => time()]);
    }

    /**
     * @param array{type: string, jobId: string, status: string, totalCount: int, completedCount: int, failedCount: int} $summary
     */
    public function recordBulkJob(array $summary): void
    {
        $this->write(self::CACHE_KEY_LAST_BULK_JOB, $summary + ['at' => time()]);
    }

    /**
     * Dismisses the stored catalog error, e.g. from the settings screen's
     * "×" on the notice — it otherwise sits there for the full 30-day
     * cache duration even after the merchant has seen and acted on it,
     * which is exactly the stale-error clutter this exists to avoid.
     */
    public function clearCatalogError(): void
    {
        $this->clear(self::CATEGORY_CATALOG);
    }

    public function clearTrackError(): void
    {
        $this->clear(self::CATEGORY_TRACK);
    }

    /**
     * @return array{message: string, at: int}|null
     */
    public function getCatalogError(): ?array
    {
        return $this->get(self::CATEGORY_CATALOG);
    }

    /**
     * @return array{message: string, at: int}|null
     */
    public function getTrackError(): ?array
    {
        return $this->get(self::CATEGORY_TRACK);
    }

    /**
     * @return array{at: int}|null
     */
    public function getLastCatalogSuccess(): ?array
    {
        $value = $this->readRaw(self::CACHE_KEY_LAST_CATALOG_SUCCESS);

        if ($value === null || !isset($value['at'])) {
            return null;
        }

        return ['at' => (int)$value['at']];
    }

    /**
     * @return array{
     *   at: int,
     *   productCount: int,
     *   variantCount: int,
     *   itemJobCount: int,
     *   variantJobCount: int,
     *   mode: string
     * }|null
     */
    public function getLastReindex(): ?array
    {
        $value = $this->readRaw(self::CACHE_KEY_LAST_REINDEX);

        if ($value === null
            || !isset($value['at'], $value['productCount'], $value['variantCount'], $value['itemJobCount'], $value['variantJobCount'], $value['mode'])
        ) {
            return null;
        }

        return [
            'at' => (int)$value['at'],
            'productCount' => (int)$value['productCount'],
            'variantCount' => (int)$value['variantCount'],
            'itemJobCount' => (int)$value['itemJobCount'],
            'variantJobCount' => (int)$value['variantJobCount'],
            'mode' => (string)$value['mode'],
        ];
    }

    /**
     * @return array{
     *   at: int,
     *   type: string,
     *   jobId: string,
     *   status: string,
     *   totalCount: int,
     *   completedCount: int,
     *   failedCount: int
     * }|null
     */
    public function getLastBulkJob(): ?array
    {
        $value = $this->readRaw(self::CACHE_KEY_LAST_BULK_JOB);

        if ($value === null
            || !isset($value['at'], $value['type'], $value['jobId'], $value['status'], $value['totalCount'], $value['completedCount'], $value['failedCount'])
        ) {
            return null;
        }

        return [
            'at' => (int)$value['at'],
            'type' => (string)$value['type'],
            'jobId' => (string)$value['jobId'],
            'status' => (string)$value['status'],
            'totalCount' => (int)$value['totalCount'],
            'completedCount' => (int)$value['completedCount'],
            'failedCount' => (int)$value['failedCount'],
        ];
    }

    private function record(string $category, string $message): void
    {
        $cache = $this->resolveCache();

        if ($cache === null) {
            return;
        }

        $cache->set($this->cacheKey($category), [
            'message' => $message,
            'at' => time(),
        ], self::CACHE_DURATION);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readRaw(string $cacheKey): ?array
    {
        $cache = $this->resolveCache();

        if ($cache === null) {
            return null;
        }

        $value = $cache->get($cacheKey);

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function write(string $cacheKey, array $value): void
    {
        $this->resolveCache()?->set($cacheKey, $value, self::CACHE_DURATION);
    }

    /**
     * @return array{message: string, at: int}|null
     */
    private function get(string $category): ?array
    {
        $cache = $this->resolveCache();

        if ($cache === null) {
            return null;
        }

        $value = $cache->get($this->cacheKey($category));

        if (!is_array($value) || !isset($value['message'], $value['at'])) {
            return null;
        }

        return [
            'message' => (string)$value['message'],
            'at' => (int)$value['at'],
        ];
    }

    private function clear(string $category): void
    {
        $this->resolveCache()?->delete($this->cacheKey($category));
    }

    private function cacheKey(string $category): string
    {
        return self::CACHE_KEY_PREFIX . $category;
    }

    private function resolveCache(): ?CacheInterface
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            return Craft::$app->getCache();
        } catch (\Throwable) {
            return null;
        }
    }
}
