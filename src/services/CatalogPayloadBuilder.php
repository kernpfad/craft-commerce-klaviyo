<?php

namespace kernpfad\commerceklaviyo\services;

/**
 * Pure construction of Klaviyo Catalogs API request bodies for catalog
 * items and variants (verified against
 * https://developers.klaviyo.com/en/reference/create_catalog_item and
 * .../create_catalog_variant, and the `create_catalog_variant.json` OpenAPI
 * schema for the exact variant attribute list). Framework-free so it's
 * unit-testable without a Klaviyo client or Craft boot.
 *
 * Every catalog resource in this plugin uses `integration_type = '$custom'`
 * and `catalog_type = '$default'` — Klaviyo's documented convention for
 * platforms without an official pre-built integration. A resource's full
 * Klaviyo ID is always `$custom:::$default:::<externalId>`.
 */
class CatalogPayloadBuilder
{
    public const INTEGRATION_TYPE = '$custom';
    public const CATALOG_TYPE = '$default';

    /**
     * The composite ID format Klaviyo uses for every catalog resource
     * (items and variants alike): `{integration_type}:::{catalog_type}:::{external_id}`.
     */
    public static function compositeId(string $externalId): string
    {
        return self::INTEGRATION_TYPE . ':::' . self::CATALOG_TYPE . ':::' . $externalId;
    }

    /**
     * @param array<string, mixed> $metadata Merchant-configured extra
     *   catalog data (e.g. a strike-through price) with no dedicated
     *   Klaviyo catalog attribute of its own — see
     *   {@see \kernpfad\commerceklaviyo\models\Settings::$catalogFieldMappingTable}.
     *   Sent as Klaviyo's `custom_metadata` attribute — NOT `metadata`,
     *   which doesn't exist on either catalog resource and is rejected
     *   with "not a valid field for the resource" (found the hard way
     *   before finding the actual field name in Klaviyo's own Catalogs
     *   API docs).
     * @param list<string> $images Additional image URLs for Klaviyo's
     *   `images` array (first URL is also used as `image_full_url` /
     *   `image_thumbnail_url` when those are not set separately).
     * @return array<string, mixed>
     */
    public function buildItem(
        string $externalId,
        string $title,
        string $description,
        string $url,
        float $price,
        bool $published,
        ?string $imageUrl = null,
        array $metadata = [],
        array $images = [],
    ): array {
        $attributes = [
            'external_id' => $externalId,
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'price' => $price,
            'published' => $published,
            'integration_type' => self::INTEGRATION_TYPE,
            'catalog_type' => self::CATALOG_TYPE,
        ];

        $this->applyImageAttributes($attributes, $imageUrl, $images);

        if ($metadata !== []) {
            $attributes['custom_metadata'] = $metadata;
        }

        // No top-level `data.id` here on purpose: this same payload is
        // used for both create and (on a 409) update by
        // `KlaviyoClient::upsert()`, and Klaviyo rejects `data.id` on
        // create with "'id' is not a valid field for the resource"
        // (verified live), while JSON:API requires it on update.
        // `upsert()` injects it itself, only for the PATCH fallback.
        return [
            'data' => [
                'type' => 'catalog-item',
                'attributes' => $attributes,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $metadata See {@see buildItem()}'s
     *   `$metadata` param — same purpose, same `custom_metadata` attribute
     *   name, and (confirmed against Klaviyo's own Catalogs API docs)
     *   equally valid on a catalog-variant, resolved against the variant
     *   (falling back to its product) rather than the product alone.
     * @param list<string> $images See {@see buildItem()}'s `$images` param.
     * @return array<string, mixed>
     */
    public function buildVariant(
        string $externalId,
        string $itemExternalId,
        string $title,
        string $description,
        string $sku,
        int $inventoryQuantity,
        float $price,
        string $url,
        bool $published,
        ?string $imageUrl = null,
        array $metadata = [],
        array $images = [],
    ): array {
        $attributes = [
            'external_id' => $externalId,
            'title' => $title,
            'description' => $description,
            'sku' => $sku,
            'inventory_quantity' => $inventoryQuantity,
            // 1 = out-of-stock variants are excluded from dynamic product
            // recommendation feeds/blocks (verified against Klaviyo's
            // OpenAPI schema) — never recommend something you can't sell.
            'inventory_policy' => 1,
            'price' => $price,
            'url' => $url,
            'published' => $published,
            'integration_type' => self::INTEGRATION_TYPE,
            'catalog_type' => self::CATALOG_TYPE,
        ];

        $this->applyImageAttributes($attributes, $imageUrl, $images);

        if ($metadata !== []) {
            $attributes['custom_metadata'] = $metadata;
        }

        // See buildItem()'s comment: no top-level `data.id` here either,
        // same reason.
        return [
            'data' => [
                'type' => 'catalog-variant',
                'attributes' => $attributes,
                'relationships' => [
                    'item' => [
                        'data' => [
                            'type' => 'catalog-item',
                            'id' => self::compositeId($itemExternalId),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Fills `image_full_url`, `image_thumbnail_url`, and `images` from an
     * optional primary URL plus any additional gallery URLs. The primary
     * URL (or the first gallery URL) becomes both full and thumbnail —
     * Craft rarely exposes a separate thumbnail transform by default, and
     * Klaviyo accepts the same URL in both fields.
     *
     * @param array<string, mixed> $attributes
     * @param list<string> $images
     */
    private function applyImageAttributes(array &$attributes, ?string $imageUrl, array $images): void
    {
        $images = array_values(array_unique(array_filter(
            $images,
            static fn(string $url): bool => $url !== '',
        )));

        if ($imageUrl !== null && $imageUrl !== '') {
            array_unshift($images, $imageUrl);
            $images = array_values(array_unique($images));
        }

        if ($images === []) {
            return;
        }

        $attributes['image_full_url'] = $images[0];
        $attributes['image_thumbnail_url'] = $images[0];
        $attributes['images'] = $images;
    }

    /**
     * PATCH-only — no create path exists for a bare inventory update, so
     * unlike {@see buildItem()}/{@see buildVariant()} this always includes
     * `data.id`: JSON:API requires it on every update, and there's no
     * create-then-maybe-update ambiguity here for Klaviyo to reject it on.
     *
     * @return array<string, mixed>
     */
    public function buildInventoryUpdate(string $externalId, int $inventoryQuantity, bool $published): array
    {
        return [
            'data' => [
                'type' => 'catalog-variant',
                'id' => self::compositeId($externalId),
                'attributes' => [
                    'inventory_quantity' => $inventoryQuantity,
                    'published' => $published,
                ],
            ],
        ];
    }

    /**
     * PATCH-only, `published` alone — for
     * {@see \kernpfad\commerceklaviyo\jobs\UnpublishCatalogItemJob}, which
     * runs on a soft-deleted (trashed) product instead of the full
     * {@see DeleteCatalogItemJob} delete. Same reasoning as
     * {@see buildInventoryUpdate()} for why `data.id` is unconditional
     * here.
     *
     * @return array<string, mixed>
     */
    public function buildItemPublishedUpdate(string $externalId, bool $published): array
    {
        return [
            'data' => [
                'type' => 'catalog-item',
                'id' => self::compositeId($externalId),
                'attributes' => [
                    'published' => $published,
                ],
            ],
        ];
    }

    /**
     * Variant counterpart to {@see buildItemPublishedUpdate()}, for
     * {@see \kernpfad\commerceklaviyo\jobs\UnpublishCatalogVariantJob}.
     *
     * @return array<string, mixed>
     */
    public function buildVariantPublishedUpdate(string $externalId, bool $published): array
    {
        return [
            'data' => [
                'type' => 'catalog-variant',
                'id' => self::compositeId($externalId),
                'attributes' => [
                    'published' => $published,
                ],
            ],
        ];
    }

    /**
     * A Klaviyo `catalog-category` resource, keyed to a Craft category
     * element's own ID (same "Craft element ID as external_id" convention
     * as every other catalog resource here). Reused for both create and
     * (via {@see \kernpfad\commerceklaviyo\services\KlaviyoClient::upsert()})
     * update, same as {@see buildItem()} — no top-level `data.id`, no
     * `data.id` needed until the update fallback injects it.
     *
     * @return array<string, mixed>
     */
    public function buildCategory(string $externalId, string $name): array
    {
        return [
            'data' => [
                'type' => 'catalog-category',
                'attributes' => [
                    'external_id' => $externalId,
                    'name' => $name,
                    'integration_type' => self::INTEGRATION_TYPE,
                    'catalog_type' => self::CATALOG_TYPE,
                ],
            ],
        ];
    }

    /**
     * The JSON:API to-many relationship document body for
     * `POST catalog-items/{id}/relationships/categories` — deliberately
     * not attempted as part of {@see buildItem()}'s own payload: Klaviyo
     * manages an item's category associations through this dedicated
     * relationship endpoint rather than the item's own attributes, the
     * same way {@see \kernpfad\commerceklaviyo\services\KlaviyoClient::toUpdatePayload()}
     * already had to learn a catalog-variant's `relationships.item` link
     * is create-only on the item/variant resource itself. Using the
     * dedicated endpoint for categories works identically on both create
     * and update, so there's exactly one code path instead of two.
     *
     * @param string[] $categoryExternalIds
     * @return array<string, mixed>
     */
    public function buildCategoryRelationships(array $categoryExternalIds): array
    {
        return [
            'data' => array_map(
                static fn(string $externalId): array => [
                    'type' => 'catalog-category',
                    'id' => self::compositeId($externalId),
                ],
                $categoryExternalIds,
            ),
        ];
    }

    /**
     * Server-side back-in-stock subscription (verified against
     * https://developers.klaviyo.com/en/reference/create_back_in_stock_subscription
     * — explicitly documented as "designed to be called from server-side
     * applications", as opposed to the separate, public-key `/client/`
     * endpoint). Email-channel only: SMS/push consent management is out of
     * scope for this plugin.
     *
     * @return array<string, mixed>
     */
    public function buildBackInStockSubscription(string $variantExternalId, string $email): array
    {
        return [
            'data' => [
                'type' => 'back-in-stock-subscription',
                'attributes' => [
                    'channels' => ['EMAIL'],
                    'profile' => [
                        'data' => [
                            'type' => 'profile',
                            'attributes' => [
                                'email' => $email,
                            ],
                        ],
                    ],
                ],
                'relationships' => [
                    'variant' => [
                        'data' => [
                            'type' => 'catalog-variant',
                            'id' => self::compositeId($variantExternalId),
                        ],
                    ],
                ],
            ],
        ];
    }
}
