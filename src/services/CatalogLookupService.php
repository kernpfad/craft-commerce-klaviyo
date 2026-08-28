<?php

namespace kernpfad\commerceklaviyo\services;

/**
 * Read-only Klaviyo catalog lookups for the CP diagnostics panel — needs
 * the `catalogs:read` scope on the configured API key.
 */
class CatalogLookupService
{
    public function __construct(
        private readonly KlaviyoClient $client,
    ) {
    }

    /**
     * @return array{found: bool, resource?: array<string, mixed>, message?: string}
     */
    public function lookupItem(string $externalId): array
    {
        return $this->lookup('catalog-items', $externalId);
    }

    /**
     * @return array{found: bool, resource?: array<string, mixed>, message?: string}
     */
    public function lookupVariant(string $externalId): array
    {
        return $this->lookup('catalog-variants', $externalId);
    }

    /**
     * @return array{found: bool, resource?: array<string, mixed>, message?: string}
     */
    private function lookup(string $collection, string $externalId): array
    {
        $externalId = trim($externalId);

        if ($externalId === '') {
            return ['found' => false, 'message' => 'External ID is required.'];
        }

        try {
            $response = $this->client->get(
                $collection . '/' . CatalogPayloadBuilder::compositeId($externalId),
            );
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return [
                    'found' => false,
                    'message' => 'No catalog resource found in Klaviyo for this Craft element ID.',
                ];
            }

            throw $e;
        }

        $resource = $response['data'] ?? null;

        if (!is_array($resource)) {
            return [
                'found' => false,
                'message' => 'Unexpected Klaviyo response shape.',
            ];
        }

        return [
            'found' => true,
            'resource' => $resource,
        ];
    }
}
