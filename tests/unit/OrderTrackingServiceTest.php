<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use craft\commerce\elements\Order;
use craft\elements\Address;
use kernpfad\commerceklaviyo\services\OrderTrackingService;
use kernpfad\commerceklaviyo\services\ProfileMapper;
use PHPUnit\Framework\TestCase;

class OrderTrackingServiceTest extends TestCase
{
    public function testResolveCheckoutUrlUsesOrdersLoadCartUrl(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getLoadCartUrl')->willReturn('https://example.com/actions/commerce/cart/load-cart?number=abc');

        $service = new OrderTrackingService();

        self::assertSame(
            'https://example.com/actions/commerce/cart/load-cart?number=abc',
            $service->resolveCheckoutUrl($order),
        );
    }

    public function testResolveCheckoutUrlReturnsEmptyStringWhenUnavailable(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getLoadCartUrl')->willReturn(null);

        self::assertSame('', (new OrderTrackingService())->resolveCheckoutUrl($order));
    }

    /**
     * Console-context order saves (import scripts, migrations, queue jobs)
     * hit an UnknownMethodException inside Commerce's own
     * Carts::getLoadCartUrl() -- it calls $request->setIsCpRequest(), which
     * only exists on craft\web\Request. resolveCheckoutUrl() must swallow
     * that (and any other failure building the URL) rather than letting it
     * propagate out of an Order::EVENT_AFTER_SAVE handler and break the save.
     */
    public function testResolveCheckoutUrlReturnsEmptyStringWhenOrderThrows(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getLoadCartUrl')->willThrowException(
            new \yii\base\UnknownMethodException('Calling unknown method: craft\console\Request::setIsCpRequest()')
        );

        self::assertSame('', (new OrderTrackingService())->resolveCheckoutUrl($order));
    }

    public function testBuildProfileMergesGuestAddressMapping(): void
    {
        $billingAddress = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['canGetProperty', 'getFieldValue'])
            ->getMock();
        $billingAddress->firstName = 'Guest';
        $billingAddress->lastName = 'Shopper';
        $billingAddress->method('canGetProperty')->willReturnCallback(
            fn(string $name): bool => in_array($name, ['firstName', 'lastName'], true),
        );

        $order = $this->createMock(Order::class);
        $order->method('getCustomer')->willReturn(null);
        $order->method('getBillingAddress')->willReturn($billingAddress);
        $order->method('getShippingAddress')->willReturn(null);

        $service = new OrderTrackingService(
            profileMapper: new ProfileMapper(),
            profileFieldMapping: ['firstName' => '$first_name', 'lastName' => '$last_name'],
        );

        $profile = $service->buildProfile('guest@example.com', $order);

        self::assertSame('guest@example.com', $profile['email']);
        self::assertSame('Guest', $profile['$first_name']);
        self::assertSame('Shopper', $profile['$last_name']);
    }
}
