<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use kernpfad\commerceklaviyo\services\OnsiteTrackingPayloadBuilder;
use kernpfad\commerceklaviyo\services\OnsiteTrackingService;
use PHPUnit\Framework\TestCase;

class OnsiteTrackingPayloadBuilderTest extends TestCase
{
    private OnsiteTrackingPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new OnsiteTrackingPayloadBuilder();
    }

    public function testBuildItemPropertiesUsesVariantIdAsProductId(): void
    {
        $payload = $this->builder->buildItemProperties(
            variantId: 20,
            title: 'Test Mug',
            sku: 'MUG-1',
            categoryName: 'Apparel',
            url: 'https://example.com/products/test',
            imageUrl: 'https://example.com/image.jpg',
            price: 19.99,
            compareAtPrice: 24.99,
        );

        self::assertSame('20', $payload['ProductID']);
        self::assertSame('Test Mug', $payload['ProductName']);
        self::assertSame('MUG-1', $payload['SKU']);
        self::assertSame(['Apparel'], $payload['Categories']);
        self::assertSame(19.99, $payload['Price']);
        self::assertSame(24.99, $payload['CompareAtPrice']);
    }

    public function testBuildAddedToCartIncludesAddedItemAndCartItems(): void
    {
        $lineItem = $this->createMock(LineItem::class);
        $lineItem->method('getPurchasable')->willReturn(null);
        $lineItem->method('getDescription')->willReturn('Ignored when purchasable missing');

        $cart = $this->createMock(Order::class);
        $cart->method('getLineItems')->willReturn([$lineItem]);

        self::assertNull($this->builder->buildAddedToCart($cart, $lineItem));
    }

    public function testAppendCartTrackingInjectsPayloadOnce(): void
    {
        OnsiteTrackingService::resetRequestState();

        $service = new OnsiteTrackingService(publicApiKey: 'AbCd12');
        $cart = $this->createMock(Order::class);

        $empty = $service->appendCartTracking(['number' => 'abc'], $cart);
        self::assertArrayNotHasKey('commerceKlaviyo', $empty);

        $reflection = new \ReflectionProperty(OnsiteTrackingService::class, 'pendingAddedToCart');
        $reflection->setAccessible(true);
        $reflection->setValue(null, [
            'AddedItemProductID' => '2',
            '$value' => 10.0,
        ]);

        $cartInfo = $service->appendCartTracking(['number' => 'abc'], $cart);
        self::assertSame('2', $cartInfo['commerceKlaviyo']['addedToCart']['AddedItemProductID']);

        $again = $service->appendCartTracking(['number' => 'abc'], $cart);
        self::assertArrayNotHasKey('commerceKlaviyo', $again);
    }
}
