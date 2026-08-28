<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\services\ProfileMapper;
use PHPUnit\Framework\TestCase;

class ProfileMapperTest extends TestCase
{
    private ProfileMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ProfileMapper();
    }

    public function testMapsConfiguredFieldsToTheirKlaviyoPropertyKeys(): void
    {
        $properties = $this->mapper->mapProperties(
            ['phoneNumber' => 'phone_number', 'loyaltyTier' => 'loyalty_tier'],
            ['phoneNumber' => '+15551234567', 'loyaltyTier' => 'Gold', 'unrelatedField' => 'ignored'],
        );

        self::assertSame(['phone_number' => '+15551234567', 'loyalty_tier' => 'Gold'], $properties);
    }

    public function testFieldsNotInTheMappingAreNeverIncluded(): void
    {
        $properties = $this->mapper->mapProperties([], ['phoneNumber' => '+15551234567']);

        self::assertSame([], $properties);
    }

    public function testSkipsMappedFieldsThatAreEmptyOrNull(): void
    {
        $properties = $this->mapper->mapProperties(
            ['phoneNumber' => 'phone_number', 'nickname' => 'nickname'],
            ['phoneNumber' => null, 'nickname' => ''],
        );

        self::assertSame([], $properties);
    }

    public function testSkipsAMappingEntryWhoseFieldValueWasNeverProvided(): void
    {
        $properties = $this->mapper->mapProperties(['missingField' => 'some_property'], []);

        self::assertSame([], $properties);
    }
}
