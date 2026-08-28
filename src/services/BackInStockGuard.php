<?php

namespace kernpfad\commerceklaviyo\services;

/**
 * Server-side guard for back-in-stock signups. Themes should hide the form
 * when a variant is in stock, but the public action must enforce the same
 * rules so bots and crafted POSTs cannot subscribe while stock is available.
 */
final class BackInStockGuard
{
    /**
     * Whether a variant may accept a back-in-stock subscription right now.
     * Uses real Commerce stock — not {@see CatalogSyncService::reportableQuantity()},
     * which may report a high placeholder above the inventory threshold.
     */
    public static function isEligible(bool $inventoryTracked, int $stock): bool
    {
        return $inventoryTracked && $stock <= 0;
    }
}
