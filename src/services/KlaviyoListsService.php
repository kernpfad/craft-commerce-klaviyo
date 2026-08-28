<?php

namespace kernpfad\commerceklaviyo\services;

/**
 * Fetches Klaviyo lists for the control-panel list picker and exposes each
 * list's opt-in process (single vs double opt-in).
 *
 * `CommerceKlaviyo::settingsHtml()` calls {@see getOptInProcessForList()}
 * unconditionally whenever a list ID is already configured — on every load
 * of the settings screen, not just when a merchant clicks "Refresh lists".
 * Nothing upstream of that call catches an exception, so an invalid or
 * expired API key previously took the *entire* settings page down with a
 * 500 (verified live: a real 401 from Klaviyo propagated all the way up
 * through `getOptInProcessForList()` → `settingsHtml()` → the plugin
 * settings controller), leaving a merchant unable to even open the screen
 * to fix the key that broke it. Failures are now caught here and treated
 * as "no lists available" instead.
 */
class KlaviyoListsService
{
    private const CACHE_KEY = 'commerce-klaviyo:lists';
    private const CACHE_DURATION = 300;

    public function __construct(
        private readonly ?KlaviyoClient $client = null,
        private readonly ?\yii\caching\CacheInterface $cache = null,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, optInProcess: string|null}>
     */
    public function getLists(): array
    {
        if ($this->client === null) {
            return [];
        }

        $cache = $this->resolveCache();

        if ($cache !== null) {
            $cached = $cache->get(self::CACHE_KEY);

            if (is_array($cached)) {
                /** @var list<array{id: string, name: string, optInProcess: string|null}> $cached */
                return $cached;
            }
        }

        $lists = $this->fetchAllLists();
        $cache?->set(self::CACHE_KEY, $lists, self::CACHE_DURATION);

        return $lists;
    }

    public function getOptInProcessForList(string $listId): ?string
    {
        foreach ($this->getLists() as $list) {
            if ($list['id'] === $listId) {
                return $list['optInProcess'];
            }
        }

        return null;
    }

    /**
     * Same as {@see getOptInProcessForList()}, but never throws — used by
     * `CommerceKlaviyo::settingsHtml()`, which renders on every load of the
     * settings screen (not just an explicit "Refresh lists" click) and has
     * nothing upstream to catch a Klaviyo API failure. The "Refresh lists"
     * button itself goes through {@see \kernpfad\commerceklaviyo\controllers\cp\ListsController},
     * which already has its own try/catch and should keep surfacing the
     * real error — this method exists so fixing the settings-page crash
     * doesn't also silence that.
     */
    public function getOptInProcessForListSafely(string $listId): ?string
    {
        try {
            return $this->getOptInProcessForList($listId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{id: string, name: string, optInProcess: string|null}>
     */
    private function fetchAllLists(): array
    {
        if ($this->client === null) {
            return [];
        }

        $lists = [];
        $path = 'lists/?fields[list]=name,opt_in_process';

        while ($path !== '') {
            $response = $this->client->get($path);

            foreach ($response['data'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = $item['id'] ?? null;
                $attributes = $item['attributes'] ?? [];

                if (!is_string($id) || !is_array($attributes)) {
                    continue;
                }

                $lists[] = [
                    'id' => $id,
                    'name' => is_string($attributes['name'] ?? null) ? $attributes['name'] : $id,
                    'optInProcess' => is_string($attributes['opt_in_process'] ?? null)
                        ? $attributes['opt_in_process']
                        : null,
                ];
            }

            $next = $response['links']['next'] ?? null;
            $path = is_string($next) ? $this->relativePathFromUrl($next) : '';
        }

        return $lists;
    }

    private function relativePathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        if (!is_string($path)) {
            return '';
        }

        $path = preg_replace('#^/api/#', '', $path) ?? '';

        if (is_string($query) && $query !== '') {
            return $path . '?' . $query;
        }

        return $path;
    }

    private function resolveCache(): ?\yii\caching\CacheInterface
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            return \Craft::$app->getCache();
        } catch (\Throwable) {
            return null;
        }
    }
}
