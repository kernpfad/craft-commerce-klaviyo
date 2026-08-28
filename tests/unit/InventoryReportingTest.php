<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\models\Settings;
use kernpfad\commerceklaviyo\services\CatalogSyncService;
use PHPUnit\Framework\TestCase;

class InventoryReportingTest extends TestCase
{
    public function testUntrackedVariantsAlwaysReportTheHighPlaceholder(): void
    {
        self::assertSame(
            999999,
            CatalogSyncService::reportableQuantity(false, 0, 10)
        );
    }

    public function testTrackedStockIsReportedAsIsWhenNoThresholdIsSet(): void
    {
        self::assertSame(42, CatalogSyncService::reportableQuantity(true, 42, null));
        self::assertSame(42, CatalogSyncService::reportableQuantity(true, 42, 0));
    }

    public function testTrackedStockAboveTheThresholdUsesTheHighPlaceholder(): void
    {
        self::assertSame(
            999999,
            CatalogSyncService::reportableQuantity(true, 11, 10)
        );
    }

    public function testTrackedStockAtOrBelowTheThresholdIsReportedAsIs(): void
    {
        self::assertSame(10, CatalogSyncService::reportableQuantity(true, 10, 10));
        self::assertSame(3, CatalogSyncService::reportableQuantity(true, 3, 10));
        self::assertSame(0, CatalogSyncService::reportableQuantity(true, 0, 10));
    }

    public function testInventoryReportingThresholdDefaultsToNull(): void
    {
        $settings = new Settings();

        self::assertNull($settings->inventoryReportingThreshold);
    }

    public function testNormalizeInventoryReportingThresholdMapsEmptyToNull(): void
    {
        self::assertNull(Settings::normalizeInventoryReportingThreshold(''));
        self::assertNull(Settings::normalizeInventoryReportingThreshold(null));
    }

    public function testNormalizeInventoryReportingThresholdCastsNumericValues(): void
    {
        self::assertSame(10, Settings::normalizeInventoryReportingThreshold('10'));
        self::assertSame(10, Settings::normalizeInventoryReportingThreshold(10));
    }

    public function testInventoryReportingThresholdHasAnIntegerMinRule(): void
    {
        $settings = new Settings();
        $reflection = new \ReflectionMethod($settings, 'defineRules');
        $reflection->setAccessible(true);

        $rules = $reflection->invoke($settings);

        $found = false;

        foreach ($rules as $rule) {
            if (!in_array('inventoryReportingThreshold', (array)$rule[0], true)) {
                continue;
            }

            if ($rule[1] === 'integer' && ($rule['min'] ?? null) === 0) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Expected an integer min:0 rule for inventoryReportingThreshold.');
    }
}
