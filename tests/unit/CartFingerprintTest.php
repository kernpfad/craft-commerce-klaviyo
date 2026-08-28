<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\tests\unit;

use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use kernpfad\commerceklaviyo\services\OrderTrackingService;
use PHPUnit\Framework\TestCase;

class CartFingerprintTest extends TestCase
{
    public function testFingerprintChangesWhenLineItemQuantityChanges(): void
    {
        $service = new OrderTrackingService();

        $before = $service->buildCartFingerprint($this->orderWithItems([
            $this->lineItem(id: 1, purchasableId: 10, qty: 1),
        ], total: 19.99));

        $after = $service->buildCartFingerprint($this->orderWithItems([
            $this->lineItem(id: 1, purchasableId: 10, qty: 2),
        ], total: 39.98));

        self::assertNotSame($before, $after);
        self::assertTrue($service->hasCartContentChanged(
            $this->orderWithItems([
                $this->lineItem(id: 1, purchasableId: 10, qty: 2),
            ], total: 39.98),
            $before,
        ));
    }

    public function testFingerprintUnchangedWhenOnlyAddressWouldDiffer(): void
    {
        $service = new OrderTrackingService();
        $items = [$this->lineItem(id: 1, purchasableId: 10, qty: 1)];

        $first = $service->buildCartFingerprint($this->orderWithItems($items, total: 19.99));
        $second = $service->buildCartFingerprint($this->orderWithItems($items, total: 19.99));

        self::assertSame($first, $second);
        self::assertFalse($service->hasCartContentChanged(
            $this->orderWithItems($items, total: 19.99),
            $first,
        ));
    }

    public function testFingerprintIgnoresLineItemOrder(): void
    {
        $service = new OrderTrackingService();

        $a = $service->buildCartFingerprint($this->orderWithItems([
            $this->lineItem(id: 1, purchasableId: 10, qty: 1),
            $this->lineItem(id: 2, purchasableId: 20, qty: 3),
        ], total: 50.0));

        $b = $service->buildCartFingerprint($this->orderWithItems([
            $this->lineItem(id: 2, purchasableId: 20, qty: 3),
            $this->lineItem(id: 1, purchasableId: 10, qty: 1),
        ], total: 50.0));

        self::assertSame($a, $b);
    }

    public function testHasCartContentChangedWhenNoPreviousFingerprint(): void
    {
        $service = new OrderTrackingService();
        $order = $this->orderWithItems([
            $this->lineItem(id: 1, purchasableId: 10, qty: 1),
        ], total: 10.0);

        self::assertTrue($service->hasCartContentChanged($order, null));
    }

    /**
     * @param list<LineItem> $lineItems
     */
    private function orderWithItems(array $lineItems, float $total): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getLineItems')->willReturn($lineItems);
        $order->method('getTotal')->willReturn($total);

        return $order;
    }

    private function lineItem(int $id, int $purchasableId, int $qty): LineItem
    {
        $lineItem = $this->getMockBuilder(LineItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $lineItem->id = $id;
        $lineItem->purchasableId = $purchasableId;
        $lineItem->qty = $qty;

        return $lineItem;
    }
}
