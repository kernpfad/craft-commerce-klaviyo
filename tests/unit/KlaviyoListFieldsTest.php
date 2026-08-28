<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\fields\ListField;
use kernpfad\commerceklaviyo\fields\ListsField;
use kernpfad\commerceklaviyo\models\KlaviyoListOption;
use PHPUnit\Framework\TestCase;

class KlaviyoListFieldsTest extends TestCase
{
    public function testListFieldNormalizesStoredIdWithoutApi(): void
    {
        $field = new ListField();
        $normalized = $field->normalizeValue('abc123', null);

        self::assertInstanceOf(KlaviyoListOption::class, $normalized);
        self::assertSame('abc123', $normalized->id);
        self::assertSame('abc123', $field->serializeValue($normalized, null));
    }

    public function testListsFieldRoundTripsJsonIds(): void
    {
        $field = new ListsField();
        $normalized = $field->normalizeValue('["list-a","list-b"]', null);

        self::assertCount(2, $normalized);
        self::assertInstanceOf(KlaviyoListOption::class, $normalized[0]);
        self::assertSame('list-a', $normalized[0]->id);
        self::assertSame('["list-a","list-b"]', $field->serializeValue($normalized, null));
    }
}
