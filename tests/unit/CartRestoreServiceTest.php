<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\tests\unit;

use craft\commerce\elements\Order;
use kernpfad\commerceklaviyo\services\CartRestoreService;
use kernpfad\commerceklaviyo\services\OrderTrackingService;
use PHPUnit\Framework\TestCase;

class CartRestoreServiceTest extends TestCase
{
    public function testReturnsLoadCartUrlForIncompleteOrder(): void
    {
        $order = $this->createMock(Order::class);
        $order->isCompleted = false;
        $order->method('getLoadCartUrl')->willReturn(
            'https://shop.test/actions/commerce/cart/load-cart?number=abc123',
        );

        $service = new CartRestoreService(new OrderTrackingService());

        self::assertSame(
            'https://shop.test/actions/commerce/cart/load-cart?number=abc123',
            $service->resolveLoadCartUrl($order),
        );
    }

    public function testReturnsNullForCompletedOrder(): void
    {
        $order = $this->createMock(Order::class);
        $order->isCompleted = true;
        $order->method('getLoadCartUrl')->willReturn(
            'https://shop.test/actions/commerce/cart/load-cart?number=abc123',
        );

        $service = new CartRestoreService(new OrderTrackingService());

        self::assertNull($service->resolveLoadCartUrl($order));
    }

    public function testReturnsNullWhenLoadCartUrlUnavailable(): void
    {
        $order = $this->createMock(Order::class);
        $order->isCompleted = false;
        $order->method('getLoadCartUrl')->willReturn(null);

        $service = new CartRestoreService(new OrderTrackingService());

        self::assertNull($service->resolveLoadCartUrl($order));
    }
}
