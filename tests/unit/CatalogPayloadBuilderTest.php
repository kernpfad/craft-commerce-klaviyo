<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\services\CatalogPayloadBuilder;
use PHPUnit\Framework\TestCase;

class CatalogPayloadBuilderTest extends TestCase
{
    private CatalogPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CatalogPayloadBuilder();
    }

    public function testCompositeIdUsesTheDocumentedCustomIntegrationFormat(): void
    {
        self::assertSame('$custom:::$default:::42', $this->builder->compositeId('42'));
    }

    public function testBuildItemProducesTheJsonApiShape(): void
    {
        $payload = $this->builder->buildItem('1', 'A Shirt', 'A nice shirt', 'https://example.com/shirt', 29.99, true);

        self::assertSame('catalog-item', $payload['data']['type']);
        self::assertSame('1', $payload['data']['attributes']['external_id']);
        self::assertSame('A Shirt', $payload['data']['attributes']['title']);
        self::assertSame(29.99, $payload['data']['attributes']['price']);
        self::assertTrue($payload['data']['attributes']['published']);
        self::assertSame('$custom', $payload['data']['attributes']['integration_type']);
        self::assertSame('$default', $payload['data']['attributes']['catalog_type']);
        self::assertArrayNotHasKey('image_full_url', $payload['data']['attributes']);
    }

    public function testBuildItemNeverIncludesDataId(): void
    {
        // Klaviyo rejects `data.id` on create ("not a valid field for the
        // resource") -- verified live. This payload is reused for both
        // create and (via KlaviyoClient::upsert()) the update fallback, so
        // the id must NOT be baked in here; upsert() injects it itself,
        // only for the PATCH request.
        $payload = $this->builder->buildItem('42', 'A Shirt', 'A nice shirt', 'https://example.com/shirt', 29.99, true);

        self::assertArrayNotHasKey('id', $payload['data']);
    }

    public function testBuildItemIncludesImageUrlOnlyWhenProvided(): void
    {
        $payload = $this->builder->buildItem('1', 'A Shirt', 'A nice shirt', 'https://example.com/shirt', 29.99, true, 'https://example.com/shirt.jpg');

        self::assertSame('https://example.com/shirt.jpg', $payload['data']['attributes']['image_full_url']);
        self::assertSame('https://example.com/shirt.jpg', $payload['data']['attributes']['image_thumbnail_url']);
        self::assertSame(['https://example.com/shirt.jpg'], $payload['data']['attributes']['images']);
    }

    public function testBuildItemIncludesImagesGalleryAndDedupesPrimary(): void
    {
        $payload = $this->builder->buildItem(
            '1',
            'A Shirt',
            'A nice shirt',
            'https://example.com/shirt',
            29.99,
            true,
            'https://example.com/shirt.jpg',
            images: [
                'https://example.com/shirt.jpg',
                'https://example.com/shirt-back.jpg',
            ],
        );

        self::assertSame('https://example.com/shirt.jpg', $payload['data']['attributes']['image_full_url']);
        self::assertSame(
            ['https://example.com/shirt.jpg', 'https://example.com/shirt-back.jpg'],
            $payload['data']['attributes']['images'],
        );
    }

    public function testBuildVariantIncludesImagesGallery(): void
    {
        $payload = $this->builder->buildVariant(
            '1-md',
            '1',
            'Medium',
            'A nice shirt',
            'SKU-MD',
            3,
            29.99,
            'https://example.com/shirt',
            true,
            images: ['https://example.com/md.jpg', 'https://example.com/md-2.jpg'],
        );

        self::assertSame('https://example.com/md.jpg', $payload['data']['attributes']['image_full_url']);
        self::assertSame('https://example.com/md.jpg', $payload['data']['attributes']['image_thumbnail_url']);
        self::assertSame(
            ['https://example.com/md.jpg', 'https://example.com/md-2.jpg'],
            $payload['data']['attributes']['images'],
        );
    }

    public function testBuildItemOmitsCustomMetadataWhenEmpty(): void
    {
        $payload = $this->builder->buildItem('1', 'A Shirt', 'A nice shirt', 'https://example.com/shirt', 29.99, true);

        self::assertArrayNotHasKey('custom_metadata', $payload['data']['attributes']);
    }

    public function testBuildItemIncludesCustomMetadataWhenProvided(): void
    {
        // The Klaviyo attribute is `custom_metadata`, not `metadata` --
        // `metadata` doesn't exist on either catalog resource and is
        // rejected outright with "not a valid field for the resource",
        // confirmed against Klaviyo's own Catalogs API docs after finding
        // that out the hard way first.
        $payload = $this->builder->buildItem(
            '1',
            'A Shirt',
            'A nice shirt',
            'https://example.com/shirt',
            29.99,
            true,
            metadata: ['compare_at_price' => 39.99],
        );

        self::assertSame(['compare_at_price' => 39.99], $payload['data']['attributes']['custom_metadata']);
        self::assertArrayNotHasKey('metadata', $payload['data']['attributes']);
    }

    public function testBuildVariantLinksToItsParentItemByCompositeId(): void
    {
        $payload = $this->builder->buildVariant('1-md', '1', 'Medium', 'A nice shirt', 'SHIRT-MD', 5, 29.99, 'https://example.com/shirt', true);

        self::assertSame('catalog-variant', $payload['data']['type']);
        self::assertSame(5, $payload['data']['attributes']['inventory_quantity']);
        self::assertSame('SHIRT-MD', $payload['data']['attributes']['sku']);
        self::assertSame(
            '$custom:::$default:::1',
            $payload['data']['relationships']['item']['data']['id']
        );
    }

    public function testBuildVariantNeverIncludesDataId(): void
    {
        // See buildItem()'s matching test -- same reason, same fix.
        $payload = $this->builder->buildVariant('1-md', '1', 'Medium', 'A nice shirt', 'SHIRT-MD', 5, 29.99, 'https://example.com/shirt', true);

        self::assertArrayNotHasKey('id', $payload['data']);
    }

    public function testBuildVariantIncludesCustomMetadataWhenProvided(): void
    {
        $payload = $this->builder->buildVariant(
            '1-md',
            '1',
            'Medium',
            'A nice shirt',
            'SHIRT-MD',
            5,
            29.99,
            'https://example.com/shirt',
            true,
            metadata: ['compare_at_price' => 39.99],
        );

        self::assertSame(['compare_at_price' => 39.99], $payload['data']['attributes']['custom_metadata']);
    }

    public function testBuildInventoryUpdateOnlyTouchesInventoryAndPublishedState(): void
    {
        $payload = $this->builder->buildInventoryUpdate('1-md', 0, false);

        self::assertSame([
            'data' => [
                'type' => 'catalog-variant',
                'id' => '$custom:::$default:::1-md',
                'attributes' => [
                    'inventory_quantity' => 0,
                    'published' => false,
                ],
            ],
        ], $payload);
    }

    public function testBuildItemPublishedUpdateOnlyTouchesPublishedState(): void
    {
        $payload = $this->builder->buildItemPublishedUpdate('1', false);

        self::assertSame([
            'data' => [
                'type' => 'catalog-item',
                'id' => '$custom:::$default:::1',
                'attributes' => [
                    'published' => false,
                ],
            ],
        ], $payload);
    }

    public function testBuildVariantPublishedUpdateOnlyTouchesPublishedState(): void
    {
        $payload = $this->builder->buildVariantPublishedUpdate('1-md', false);

        self::assertSame([
            'data' => [
                'type' => 'catalog-variant',
                'id' => '$custom:::$default:::1-md',
                'attributes' => [
                    'published' => false,
                ],
            ],
        ], $payload);
    }

    public function testBuildCategoryProducesTheJsonApiShapeWithoutADataId(): void
    {
        // No `data.id`, same reason as buildItem()/buildVariant(): reused
        // for both create and update via KlaviyoClient::upsert().
        $payload = $this->builder->buildCategory('7', 'Shirts');

        self::assertSame('catalog-category', $payload['data']['type']);
        self::assertArrayNotHasKey('id', $payload['data']);
        self::assertSame('7', $payload['data']['attributes']['external_id']);
        self::assertSame('Shirts', $payload['data']['attributes']['name']);
        self::assertSame('$custom', $payload['data']['attributes']['integration_type']);
        self::assertSame('$default', $payload['data']['attributes']['catalog_type']);
    }

    public function testBuildCategoryRelationshipsListsEachCategoryByCompositeId(): void
    {
        $payload = $this->builder->buildCategoryRelationships(['7', '9']);

        self::assertSame([
            ['type' => 'catalog-category', 'id' => '$custom:::$default:::7'],
            ['type' => 'catalog-category', 'id' => '$custom:::$default:::9'],
        ], $payload['data']);
    }

    public function testBuildCategoryRelationshipsWithNoCategoriesIsAnEmptyList(): void
    {
        $payload = $this->builder->buildCategoryRelationships([]);

        self::assertSame([], $payload['data']);
    }

    public function testBuildBackInStockSubscriptionIsEmailChannelOnly(): void
    {
        $payload = $this->builder->buildBackInStockSubscription('1-md', 'shopper@example.com');

        self::assertSame(['EMAIL'], $payload['data']['attributes']['channels']);
        self::assertSame('shopper@example.com', $payload['data']['attributes']['profile']['data']['attributes']['email']);
        self::assertSame(
            '$custom:::$default:::1-md',
            $payload['data']['relationships']['variant']['data']['id']
        );
    }
}
