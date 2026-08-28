<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\services\BackInStockGuard;
use PHPUnit\Framework\TestCase;

class BackInStockGuardTest extends TestCase
{
    public function testEligibleWhenTrackedAndOutOfStock(): void
    {
        self::assertTrue(BackInStockGuard::isEligible(true, 0));
    }

    public function testIneligibleWhenNotTracked(): void
    {
        self::assertFalse(BackInStockGuard::isEligible(false, 0));
        self::assertFalse(BackInStockGuard::isEligible(false, 5));
    }

    public function testIneligibleWhenInStock(): void
    {
        self::assertFalse(BackInStockGuard::isEligible(true, 1));
        self::assertFalse(BackInStockGuard::isEligible(true, 42));
    }

    public function testEligibleAtExactlyZeroStock(): void
    {
        self::assertTrue(BackInStockGuard::isEligible(true, 0));
    }
}
