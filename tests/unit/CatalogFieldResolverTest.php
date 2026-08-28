<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\fields\data\OptionData;
use craft\models\FieldLayout;
use kernpfad\commerceklaviyo\services\CatalogFieldResolver;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;

class CatalogFieldResolverTest extends TestCase
{
    private CatalogFieldResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CatalogFieldResolver();
    }

    /**
     * A real element only returns a field value for a handle that's
     * actually on its own field layout — `Element::getFieldValue()` throws
     * otherwise. Mocked here so these tests exercise the same "handle is on
     * the layout" precondition {@see CatalogFieldResolver} checks before
     * ever calling `getFieldValue()`.
     *
     * @return ElementInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function elementWithField(string $handle): ElementInterface
    {
        $field = $this->createMock(FieldInterface::class);

        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getFieldByHandle')->with($handle)->willReturn($field);

        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn($layout);

        return $element;
    }

    public function testResolveTextReturnsNullForEmptyHandle(): void
    {
        $element = $this->createMock(ElementInterface::class);
        $element->expects(self::never())->method('getFieldValue');

        self::assertNull($this->resolver->resolveText($element, null));
        self::assertNull($this->resolver->resolveText($element, '   '));
    }

    public function testResolveTextReturnsNullWhenHandleIsNotOnTheFieldLayout(): void
    {
        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getFieldByHandle')->with('body')->willReturn(null);

        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn($layout);
        $element->expects(self::never())->method('getFieldValue');

        self::assertNull($this->resolver->resolveText($element, 'body'));
    }

    public function testResolveTextReturnsTrimmedStringValues(): void
    {
        $element = $this->elementWithField('body');
        $element->method('getFieldValue')->with('body')->willReturn('  Rich description  ');

        self::assertSame('Rich description', $this->resolver->resolveText($element, 'body'));
    }

    public function testResolveTextCastsScalars(): void
    {
        $element = $this->elementWithField('count');
        $element->method('getFieldValue')->with('count')->willReturn(42);

        self::assertSame('42', $this->resolver->resolveText($element, 'count'));
    }

    public function testResolveValueReturnsNullForEmptyHandle(): void
    {
        $element = $this->createMock(ElementInterface::class);
        $element->expects(self::never())->method('getFieldValue');

        self::assertNull($this->resolver->resolveValue($element, null));
        self::assertNull($this->resolver->resolveValue($element, '   '));
    }

    public function testResolveValueReturnsNullWhenHandleIsNotOnTheFieldLayout(): void
    {
        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getFieldByHandle')->with('salePrice')->willReturn(null);

        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn($layout);
        $element->expects(self::never())->method('getFieldValue');

        self::assertNull($this->resolver->resolveValue($element, 'salePrice'));
    }

    public function testResolveValueReturnsTrimmedStrings(): void
    {
        $element = $this->elementWithField('note');
        $element->method('getFieldValue')->with('note')->willReturn('  Limited edition  ');

        self::assertSame('Limited edition', $this->resolver->resolveValue($element, 'note'));
    }

    public function testResolveValuePreservesNumbersAndBooleans(): void
    {
        $element = $this->elementWithField('discountPercent');
        $element->method('getFieldValue')->with('discountPercent')->willReturn(15.5);

        self::assertSame(15.5, $this->resolver->resolveValue($element, 'discountPercent'));

        $flagElement = $this->elementWithField('isClearance');
        $flagElement->method('getFieldValue')->with('isClearance')->willReturn(true);

        self::assertTrue($this->resolver->resolveValue($flagElement, 'isClearance'));
    }

    public function testResolveValueUnwrapsMoneyFieldsToMajorUnitFloats(): void
    {
        $element = $this->elementWithField('salePrice');
        $element->method('getFieldValue')->with('salePrice')->willReturn(new Money('3999', new Currency('EUR')));

        self::assertSame(39.99, $this->resolver->resolveValue($element, 'salePrice'));
    }

    public function testResolveValueReturnsNullForNonScalarValues(): void
    {
        $asset = $this->createMock(Asset::class);

        $element = $this->elementWithField('gallery');
        $element->method('getFieldValue')->with('gallery')->willReturn([$asset]);

        self::assertNull($this->resolver->resolveValue($element, 'gallery'));
    }

    public function testResolveValueResolvesRelationFieldIdsAsACommaJoinedList(): void
    {
        $categoryA = $this->createMock(Category::class);
        $categoryA->id = 3;
        $categoryB = $this->createMock(Category::class);
        $categoryB->id = 9;

        $element = $this->elementWithField('productCategories');
        $element->method('getFieldValue')->with('productCategories')->willReturn([$categoryA, $categoryB]);

        self::assertSame('3, 9', $this->resolver->resolveValue($element, 'productCategories.id'));
    }

    public function testResolveValueResolvesRelationFieldTitlesAsACommaJoinedList(): void
    {
        $categoryA = $this->createMock(Category::class);
        $categoryA->title = 'Shirts';
        $categoryB = $this->createMock(Category::class);
        $categoryB->title = 'Sale';

        $element = $this->elementWithField('productCategories');
        $element->method('getFieldValue')->with('productCategories')->willReturn([$categoryA, $categoryB]);

        self::assertSame('Shirts, Sale', $this->resolver->resolveValue($element, 'productCategories.title'));
    }

    public function testResolveValueReturnsNullForAnUnsupportedRelationProperty(): void
    {
        $element = $this->createMock(ElementInterface::class);
        $element->expects(self::never())->method('getFieldLayout');

        self::assertNull($this->resolver->resolveValue($element, 'productCategories.slug'));
    }

    public function testResolveValueReturnsNullForARelationPropertyWhoseHandleIsNotOnTheFieldLayout(): void
    {
        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getFieldByHandle')->with('productCategories')->willReturn(null);

        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn($layout);
        $element->expects(self::never())->method('getFieldValue');

        self::assertNull($this->resolver->resolveValue($element, 'productCategories.title'));
    }

    public function testResolveValueSkipsEmptyOrNonElementRelationEntries(): void
    {
        $category = $this->createMock(Category::class);
        $category->title = 'Shirts';

        $element = $this->elementWithField('productCategories');
        $element->method('getFieldValue')->with('productCategories')->willReturn([$category, 'not-an-element', null]);

        self::assertSame('Shirts', $this->resolver->resolveValue($element, 'productCategories.title'));
    }

    public function testResolveValueResolvesADropdownFieldsValue(): void
    {
        // Dropdown/Radio Buttons return a single OptionData directly, not
        // wrapped in a collection -- unlike Checkboxes/Multi-select.
        $element = $this->elementWithField('material');
        $element->method('getFieldValue')->with('material')->willReturn(new OptionData('Cotton', 'cotton', true));

        self::assertSame('cotton', $this->resolver->resolveValue($element, 'material.value'));
        self::assertSame('Cotton', $this->resolver->resolveValue($element, 'material.label'));
    }

    public function testResolveValueResolvesACheckboxesFieldsSelectedValuesAsACommaJoinedList(): void
    {
        $element = $this->elementWithField('features');
        $element->method('getFieldValue')->with('features')->willReturn([
            new OptionData('Waterproof', 'waterproof', true),
            new OptionData('Wireless', 'wireless', true),
        ]);

        self::assertSame('waterproof, wireless', $this->resolver->resolveValue($element, 'features.value'));
        self::assertSame('Waterproof, Wireless', $this->resolver->resolveValue($element, 'features.label'));
    }

    public function testResolveValueReturnsNullForAnOptionsFieldWhenAskedForARelationProperty(): void
    {
        $element = $this->elementWithField('material');
        $element->method('getFieldValue')->with('material')->willReturn(new OptionData('Cotton', 'cotton', true));

        self::assertNull($this->resolver->resolveValue($element, 'material.id'));
    }

    public function testResolveImageUrlReturnsFirstAssetUrl(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getUrl')->willReturn('https://example.com/image.jpg');

        $element = $this->elementWithField('productImage');
        $element->method('getFieldValue')->with('productImage')->willReturn([$asset]);

        self::assertSame('https://example.com/image.jpg', $this->resolver->resolveImageUrl($element, 'productImage'));
    }

    public function testResolveImageUrlsReturnsEveryUsableAssetUrl(): void
    {
        $first = $this->createMock(Asset::class);
        $first->method('getUrl')->willReturn('https://example.com/a.jpg');
        $second = $this->createMock(Asset::class);
        $second->method('getUrl')->willReturn('https://example.com/b.jpg');
        $broken = $this->createMock(Asset::class);
        $broken->method('getUrl')->willReturn(null);

        $element = $this->elementWithField('productImage');
        $element->method('getFieldValue')->with('productImage')->willReturn([$first, $broken, $second]);

        self::assertSame(
            ['https://example.com/a.jpg', 'https://example.com/b.jpg'],
            $this->resolver->resolveImageUrls($element, 'productImage'),
        );
    }

    public function testResolveImageUrlsReturnsEmptyWhenHandleIsNotOnTheFieldLayout(): void
    {
        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getFieldByHandle')->with('productImage')->willReturn(null);

        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn($layout);
        $element->expects(self::never())->method('getFieldValue');

        self::assertSame([], $this->resolver->resolveImageUrls($element, 'productImage'));
    }

    public function testResolveImageUrlReturnsNullWhenAssetHasNoUrl(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getUrl')->willReturn(null);

        $element = $this->elementWithField('productImage');
        $element->method('getFieldValue')->with('productImage')->willReturn($asset);

        self::assertNull($this->resolver->resolveImageUrl($element, 'productImage'));
    }

    public function testResolveImageUrlReturnsNullWhenHandleIsNotOnTheFieldLayout(): void
    {
        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getFieldByHandle')->with('productImage')->willReturn(null);

        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn($layout);
        $element->expects(self::never())->method('getFieldValue');

        self::assertNull($this->resolver->resolveImageUrl($element, 'productImage'));
    }
}
