<?php

namespace kernpfad\commerceklaviyo\services;

use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\fields\data\OptionData;
use craft\helpers\MoneyHelper;
use Money\Money;

/**
 * Resolves project-specific custom field values on catalog elements into
 * the plain strings Klaviyo's catalog API expects. Kept framework-light
 * (only Craft element/field types, no Commerce or Klaviyo imports) so
 * it's unit-testable without booting the full app stack.
 */
class CatalogFieldResolver
{
    /**
     * Returns a trimmed string from the element's custom field, or null when
     * the handle is empty, the field is unset, or the value can't be turned
     * into meaningful catalog text.
     */
    public function resolveText(ElementInterface $element, ?string $fieldHandle): ?string
    {
        $fieldHandle = $this->handleOnLayout($element, $fieldHandle);

        if ($fieldHandle === null) {
            return null;
        }

        $value = $element->getFieldValue($fieldHandle);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $text = trim($value);

            return $text !== '' ? $text : null;
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        return null;
    }

    /**
     * Returns a scalar value (string, int, float, or bool) from any of the
     * element's custom fields, for building arbitrary Klaviyo catalog
     * `metadata` entries — unlike {@see resolveText()}, numbers and booleans
     * come back as themselves rather than being cast to strings, since
     * Klaviyo's metadata object is a real JSON object, not a bag of text.
     *
     * Craft's native Money field is unwrapped to a plain float (major
     * units, e.g. `19.99`) via {@see MoneyHelper::toDecimal()} — the most
     * likely field type behind a strike-through/promo price, one of the
     * two motivating cases for this method existing at all.
     *
     * A relation field (Categories, Entries, Tags, Assets, ...) or an
     * options field (Dropdown, Radio Buttons, Checkboxes, Multi-select)
     * has no single scalar value at all — `fieldHandle.id`/`fieldHandle.title`
     * or `fieldHandle.value`/`fieldHandle.label` resolves it instead, as a
     * comma-joined list of whatever's selected (see
     * {@see resolveRelationProperty()}). This is why
     * {@see \kernpfad\commerceklaviyo\CommerceKlaviyo::getCatalogMetadataFieldOptions()}
     * never offers either kind of field's bare handle as a mapping option
     * — it would always resolve to null.
     *
     * Any other non-scalar value (Matrix, Table, ...) returns null: this
     * plugin has no sensible generic way to flatten those into a metadata
     * entry, and a caller mapping one of those handles gets silently
     * skipped rather than sending Klaviyo a value it would reject.
     */
    public function resolveValue(ElementInterface $element, ?string $fieldHandle): string|int|float|bool|null
    {
        if ($fieldHandle !== null && str_contains($fieldHandle, '.')) {
            return $this->resolveRelationProperty($element, $fieldHandle);
        }

        $fieldHandle = $this->handleOnLayout($element, $fieldHandle);

        if ($fieldHandle === null) {
            return null;
        }

        $value = $element->getFieldValue($fieldHandle);

        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Money) {
            $decimal = MoneyHelper::toDecimal($value);

            return $decimal !== false ? (float)$decimal : null;
        }

        if (is_string($value)) {
            $text = trim($value);

            return $text !== '' ? $text : null;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Resolves `fieldHandle.id`/`fieldHandle.title` against a relation
     * field's selected elements (Categories, Entries, Tags, Assets, ...),
     * or `fieldHandle.value`/`fieldHandle.label` against an options field's
     * selected option(s) (Dropdown, Radio Buttons, Checkboxes, Multi-select
     * — none of which have any single scalar value of their own either).
     * Comma-joins however many are selected — Klaviyo's metadata value is
     * meant to be simple, and a joined list reads fine for a handful of
     * categories/tags/options without needing this plugin to invent a real
     * array-valued metadata convention.
     */
    private function resolveRelationProperty(ElementInterface $element, string $path): ?string
    {
        [$fieldHandle, $property] = array_pad(explode('.', $path, 2), 2, null);

        if (!in_array($property, ['id', 'title', 'value', 'label'], true)) {
            return null;
        }

        $fieldHandle = $this->handleOnLayout($element, $fieldHandle);

        if ($fieldHandle === null) {
            return null;
        }

        $value = $element->getFieldValue($fieldHandle);

        // Dropdown/Radio Buttons: a single OptionData, not a collection —
        // Checkboxes/Multi-select return a MultiOptionsFieldData of them.
        if ($value instanceof OptionData) {
            $value = [$value];
        }

        if (!is_iterable($value)) {
            return null;
        }

        $parts = [];

        foreach ($value as $item) {
            $part = match (true) {
                $item instanceof Element && $property === 'id' => (string)$item->id,
                $item instanceof Element && $property === 'title' => trim((string)($item->title ?? '')),
                $item instanceof OptionData && $property === 'value' => trim((string)($item->value ?? '')),
                $item instanceof OptionData && $property === 'label' => trim((string)($item->label ?? '')),
                default => null,
            };

            if ($part !== null && $part !== '') {
                $parts[] = $part;
            }
        }

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /**
     * Returns an absolute image URL from an Assets field (or a single Asset
     * value), or null when no usable image is available.
     */
    public function resolveImageUrl(ElementInterface $element, ?string $fieldHandle): ?string
    {
        $urls = $this->resolveImageUrls($element, $fieldHandle);

        return $urls[0] ?? null;
    }

    /**
     * Returns every usable absolute image URL from an Assets field (or a
     * single Asset value), in field order. Empty when the handle is unset
     * or no asset has a URL.
     *
     * @return list<string>
     */
    public function resolveImageUrls(ElementInterface $element, ?string $fieldHandle): array
    {
        $fieldHandle = $this->handleOnLayout($element, $fieldHandle);

        if ($fieldHandle === null) {
            return [];
        }

        $value = $element->getFieldValue($fieldHandle);
        $urls = [];

        if ($value instanceof Asset) {
            $url = $this->assetUrl($value);

            return $url !== null ? [$url] : [];
        }

        if (is_iterable($value)) {
            foreach ($value as $asset) {
                if (!$asset instanceof Asset) {
                    continue;
                }

                $url = $this->assetUrl($asset);

                if ($url !== null) {
                    $urls[] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * Returns the assigned Craft `Category` elements from a Categories
     * field as `[['id' => craftCategoryId, 'name' => title], ...]` — the
     * Craft element ID is reused as the Klaviyo `catalog-category`
     * `external_id`, the same convention this plugin already uses for
     * products and variants (stable, already unique, no separate
     * ID-mapping table needed).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function resolveCategories(ElementInterface $element, ?string $fieldHandle): array
    {
        $fieldHandle = $this->handleOnLayout($element, $fieldHandle);

        if ($fieldHandle === null) {
            return [];
        }

        $value = $element->getFieldValue($fieldHandle);

        if (!is_iterable($value)) {
            return [];
        }

        $categories = [];

        foreach ($value as $category) {
            if ($category instanceof Category && $category->id !== null) {
                $categories[] = [
                    'id' => (string)$category->id,
                    'name' => $category->title ?? '',
                ];
            }
        }

        return $categories;
    }

    private function assetUrl(Asset $asset): ?string
    {
        $url = $asset->getUrl();

        if (!is_string($url) || $url === '') {
            return null;
        }

        return $url;
    }

    /**
     * Trims the handle and confirms it's actually on the given element's own
     * field layout, returning null otherwise. `Element::getFieldValue()`
     * throws for a handle outside that element's layout rather than
     * returning null — a real problem here, since both resolver methods are
     * deliberately called against a variant before falling back to its
     * product (the plugin's own documented "just set it once on the
     * product" setup): confirmed live that onsite tracking's `Viewed
     * Product`/`Added to Cart` payloads crashed the whole page render/cart
     * request with exactly that configuration, since checking the variant
     * first is unconditional there.
     */
    private function handleOnLayout(ElementInterface $element, ?string $fieldHandle): ?string
    {
        if ($fieldHandle === null || trim($fieldHandle) === '') {
            return null;
        }

        $fieldHandle = trim($fieldHandle);

        if ($element->getFieldLayout()?->getFieldByHandle($fieldHandle) === null) {
            return null;
        }

        return $fieldHandle;
    }
}
