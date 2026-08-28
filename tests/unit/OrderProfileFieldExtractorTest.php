<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use craft\elements\Address;
use craft\elements\User;
use kernpfad\commerceklaviyo\services\OrderProfileFieldExtractor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderProfileFieldExtractorTest extends TestCase
{
    private OrderProfileFieldExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new OrderProfileFieldExtractor();
    }

    public function testExtractsMappedValuesFromUserWhenCustomerExists(): void
    {
        $customer = $this->createMock(User::class);
        $customer->method('getFieldValue')->willReturnMap([
            ['loyaltyTier', 'Gold'],
            ['phoneNumber', '+15551234567'],
        ]);

        $values = $this->extractor->extract(
            ['loyaltyTier', 'phoneNumber'],
            $customer,
            null,
            null,
        );

        self::assertSame([
            'loyaltyTier' => 'Gold',
            'phoneNumber' => '+15551234567',
        ], $values);
    }

    public function testExtractsMappedValuesFromBillingAddressForGuestCheckout(): void
    {
        $address = $this->createAddressMock([
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
        ]);

        $values = $this->extractor->extract(
            ['firstName', 'lastName'],
            null,
            $address,
            null,
        );

        self::assertSame([
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
        ], $values);
    }

    public function testExtractsCustomAddressFieldValuesForGuestCheckout(): void
    {
        $address = $this->createAddressMock();
        $address->method('getFieldValue')->with('phoneNumber')->willReturn('+441234567890');

        $values = $this->extractor->extract(['phoneNumber'], null, $address, null);

        self::assertSame(['phoneNumber' => '+441234567890'], $values);
    }

    public function testFallsBackToShippingAddressWhenBillingIsMissing(): void
    {
        $address = $this->createAddressMock(['firstName' => 'Grace']);

        $values = $this->extractor->extract(['firstName'], null, null, $address);

        self::assertSame(['firstName' => 'Grace'], $values);
    }

    public function testReturnsEmptyArrayWhenNoCustomerOrAddressExists(): void
    {
        self::assertSame([], $this->extractor->extract(['firstName'], null, null, null));
    }

    /**
     * Craft elements need Yii booted to construct — use a partial mock instead.
     *
     * @param array<string, mixed> $nativeAttributes
     * @return Address&MockObject
     */
    private function createAddressMock(array $nativeAttributes = []): Address
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['canGetProperty', 'getFieldValue'])
            ->getMock();

        foreach ($nativeAttributes as $name => $value) {
            $address->$name = $value;
        }

        $address->method('canGetProperty')->willReturnCallback(
            fn(string $name): bool => array_key_exists($name, $nativeAttributes),
        );

        return $address;
    }
}
