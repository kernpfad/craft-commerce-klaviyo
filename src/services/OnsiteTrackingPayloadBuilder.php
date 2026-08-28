<?php

namespace kernpfad\commerceklaviyo\services;

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;

/**
 * Builds Klaviyo onsite (JavaScript) event property bags for browse metrics.
 * Uses the same variant external IDs as {@see CatalogPayloadBuilder} and
 * {@see OrderTrackingService} so client-side events align with catalog sync
 * and server-side Ordered Product metrics.
 */
class OnsiteTrackingPayloadBuilder
{
    public function __construct(
        private readonly CatalogFieldResolver $fieldResolver = new CatalogFieldResolver(),
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildViewedProduct(
        Product $product,
        Variant $variant,
        ?string $descriptionFieldHandle = null,
        ?string $imageFieldHandle = null,
    ): ?array {
        if ($variant->id === null) {
            return null;
        }

        return $this->buildVariantItemProperties($product, $variant, $descriptionFieldHandle, $imageFieldHandle);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildAddedToCart(
        Order $cart,
        LineItem $addedLineItem,
        ?string $descriptionFieldHandle = null,
        ?string $imageFieldHandle = null,
    ): ?array {
        $purchasable = $addedLineItem->getPurchasable();

        if (!$purchasable instanceof Variant) {
            return null;
        }

        $product = $purchasable->getProduct();

        if ($product === null) {
            return null;
        }

        $addedItem = $this->buildVariantItemProperties(
            $product,
            $purchasable,
            $descriptionFieldHandle,
            $imageFieldHandle,
        );

        if ($addedItem === null) {
            return null;
        }

        $items = [];

        foreach ($cart->getLineItems() as $lineItem) {
            $variant = $lineItem->getPurchasable();

            if (!$variant instanceof Variant) {
                continue;
            }

            $lineProduct = $variant->getProduct();

            if ($lineProduct === null) {
                continue;
            }

            $item = $this->buildVariantItemProperties(
                $lineProduct,
                $variant,
                $descriptionFieldHandle,
                $imageFieldHandle,
            );

            if ($item === null) {
                continue;
            }

            $items[] = [
                'ProductID' => $item['ProductID'],
                'SKU' => $item['SKU'],
                'ProductName' => $item['ProductName'],
                'Quantity' => $lineItem->qty,
                'ItemPrice' => (float)$lineItem->getSalePrice(),
                'RowTotal' => (float)$lineItem->getSubtotal(),
                'ProductURL' => $item['URL'],
                'ImageURL' => $item['ImageURL'],
                'ProductCategories' => $item['Categories'],
            ];
        }

        $checkoutUrl = $cart->getLoadCartUrl();

        return [
            '$value' => (float)$cart->getTotal(),
            'AddedItemProductName' => $addedItem['ProductName'],
            'AddedItemProductID' => $addedItem['ProductID'],
            'AddedItemSKU' => $addedItem['SKU'],
            'AddedItemCategories' => $addedItem['Categories'],
            'AddedItemImageURL' => $addedItem['ImageURL'],
            'AddedItemURL' => $addedItem['URL'],
            'AddedItemPrice' => $addedItem['Price'],
            'AddedItemQuantity' => $addedLineItem->qty,
            'ItemNames' => array_map(fn(LineItem $lineItem): string => $lineItem->getDescription(), $cart->getLineItems()),
            'CheckoutURL' => is_string($checkoutUrl) ? $checkoutUrl : '',
            'Items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildVariantItemProperties(
        Product $product,
        Variant $variant,
        ?string $descriptionFieldHandle,
        ?string $imageFieldHandle,
    ): ?array {
        if ($variant->id === null) {
            return null;
        }

        $price = (float)$variant->getSalePrice();
        $compareAtPrice = (float)$variant->getPrice();

        return $this->buildItemProperties(
            variantId: (int)$variant->id,
            title: (string)($variant->title ?: $product->title),
            sku: (string)($variant->sku ?? ''),
            categoryName: (string)$product->getType()->name,
            url: $variant->getUrl() ?: $product->getUrl() ?: '',
            imageUrl: $this->fieldResolver->resolveImageUrl($variant, $imageFieldHandle)
                ?? $this->fieldResolver->resolveImageUrl($product, $imageFieldHandle)
                ?? '',
            price: $price,
            compareAtPrice: $compareAtPrice,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildItemProperties(
        int $variantId,
        string $title,
        string $sku,
        string $categoryName,
        string $url,
        string $imageUrl,
        float $price,
        float $compareAtPrice,
    ): array {
        return [
            'ProductName' => $title,
            'ProductID' => (string)$variantId,
            'SKU' => $sku,
            'Categories' => [$categoryName],
            'ImageURL' => $imageUrl,
            'URL' => $url,
            'Brand' => '',
            'Price' => $price,
            'CompareAtPrice' => $compareAtPrice > $price ? $compareAtPrice : $price,
        ];
    }
}
