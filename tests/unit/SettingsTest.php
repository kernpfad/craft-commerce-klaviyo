<?php

namespace kernpfad\commerceklaviyo\tests\unit;

use kernpfad\commerceklaviyo\models\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    public function testParsesOneMappingPerLine(): void
    {
        $settings = new Settings();
        $settings->profileFieldMappingRaw = "phoneNumber=phone_number\nloyaltyTier=loyalty_tier";

        self::assertSame(
            ['phoneNumber' => 'phone_number', 'loyaltyTier' => 'loyalty_tier'],
            $settings->getProfileFieldMapping()
        );
    }

    public function testTrimsWhitespaceAroundHandlesAndKeys(): void
    {
        $settings = new Settings();
        $settings->profileFieldMappingRaw = "  phoneNumber  =  phone_number  ";

        self::assertSame(['phoneNumber' => 'phone_number'], $settings->getProfileFieldMapping());
    }

    public function testIgnoresBlankLines(): void
    {
        $settings = new Settings();
        $settings->profileFieldMappingRaw = "phoneNumber=phone_number\n\n\nloyaltyTier=loyalty_tier";

        self::assertCount(2, $settings->getProfileFieldMapping());
    }

    public function testIgnoresLinesWithoutAnEqualsSign(): void
    {
        $settings = new Settings();
        $settings->profileFieldMappingRaw = "not a mapping line\nphoneNumber=phone_number";

        self::assertSame(['phoneNumber' => 'phone_number'], $settings->getProfileFieldMapping());
    }

    public function testIgnoresLinesWithAnEmptyHandleOrProperty(): void
    {
        $settings = new Settings();
        $settings->profileFieldMappingRaw = "=phone_number\nphoneNumber=";

        self::assertSame([], $settings->getProfileFieldMapping());
    }

    public function testEmptyRawValueProducesAnEmptyMapping(): void
    {
        $settings = new Settings();

        self::assertSame([], $settings->getProfileFieldMapping());
    }

    public function testCatalogFieldMappingBuildsHandleToKeyFromTableRows(): void
    {
        $settings = new Settings();
        $settings->catalogFieldMappingTable = [
            ['fieldHandle' => 'salePrice', 'klaviyoKey' => 'compare_at_price'],
            ['fieldHandle' => 'material', 'klaviyoKey' => 'material'],
        ];

        self::assertSame(
            ['salePrice' => 'compare_at_price', 'material' => 'material'],
            $settings->getCatalogFieldMapping()
        );
    }

    public function testCatalogFieldMappingSkipsRowsMissingAHandleOrKey(): void
    {
        $settings = new Settings();
        $settings->catalogFieldMappingTable = [
            ['fieldHandle' => '', 'klaviyoKey' => 'compare_at_price'],
            ['fieldHandle' => 'salePrice', 'klaviyoKey' => ''],
            ['fieldHandle' => 'material', 'klaviyoKey' => 'material'],
        ];

        self::assertSame(['material' => 'material'], $settings->getCatalogFieldMapping());
    }

    public function testCatalogFieldMappingTrimsWhitespace(): void
    {
        $settings = new Settings();
        $settings->catalogFieldMappingTable = [
            ['fieldHandle' => '  salePrice  ', 'klaviyoKey' => '  compare_at_price  '],
        ];

        self::assertSame(['salePrice' => 'compare_at_price'], $settings->getCatalogFieldMapping());
    }

    public function testEmptyCatalogFieldMappingTableProducesAnEmptyMapping(): void
    {
        $settings = new Settings();

        self::assertSame([], $settings->getCatalogFieldMapping());
    }

    public function testGetApiKeyReturnsThePlainValueUnchanged(): void
    {
        $settings = new Settings();
        $settings->apiKey = 'pk_plain_test_key';

        self::assertSame('pk_plain_test_key', $settings->getApiKey());
    }

    public function testGetApiKeyResolvesAnEnvVarReference(): void
    {
        putenv('COMMERCE_KLAVIYO_TEST_API_KEY=pk_from_env');
        $settings = new Settings();
        $settings->apiKey = '$COMMERCE_KLAVIYO_TEST_API_KEY';

        self::assertSame('pk_from_env', $settings->getApiKey());

        putenv('COMMERCE_KLAVIYO_TEST_API_KEY');
    }

    public function testGetApiKeyReturnsEmptyStringWhenUnset(): void
    {
        $settings = new Settings();

        self::assertSame('', $settings->getApiKey());
    }

    public function testGetWebhookSecretResolvesAnEnvVarReference(): void
    {
        putenv('COMMERCE_KLAVIYO_TEST_WEBHOOK_SECRET=whsec_test');
        $settings = new Settings();
        $settings->webhookSecret = '$COMMERCE_KLAVIYO_TEST_WEBHOOK_SECRET';

        self::assertSame('whsec_test', $settings->getWebhookSecret());

        putenv('COMMERCE_KLAVIYO_TEST_WEBHOOK_SECRET');
    }

    public function testQueueComponentIdDefaultsToTheCraftDefaultQueue(): void
    {
        $settings = new Settings();

        self::assertSame('queue', $settings->queueComponentId);
    }

    public function testQueueComponentIdHasARequiredValidationRule(): void
    {
        $settings = new Settings();
        $reflection = new \ReflectionMethod($settings, 'defineRules');
        $reflection->setAccessible(true);

        $rules = $reflection->invoke($settings);

        $hasRequiredRule = false;

        foreach ($rules as $rule) {
            if (in_array('queueComponentId', (array)$rule[0], true) && $rule[1] === 'required') {
                $hasRequiredRule = true;
                break;
            }
        }

        self::assertTrue($hasRequiredRule, 'Expected a "required" rule covering queueComponentId.');
    }

    public function testNewsletterSignupIsDisabledByDefault(): void
    {
        $settings = new Settings();

        self::assertFalse($settings->newsletterSignupEnabled);
    }

    public function testNewsletterFormieFormIdIsUnboundByDefault(): void
    {
        $settings = new Settings();

        self::assertNull($settings->newsletterFormieFormId);
    }

    public function testNewsletterListIdIsOnlyRequiredWhenNewsletterSignupIsEnabled(): void
    {
        $settings = new Settings();
        $reflection = new \ReflectionMethod($settings, 'defineRules');
        $reflection->setAccessible(true);

        $rules = $reflection->invoke($settings);

        $whenCallback = null;

        foreach ($rules as $rule) {
            if (in_array('newsletterListId', (array)$rule[0], true) && $rule[1] === 'required') {
                $whenCallback = $rule['when'] ?? null;
                break;
            }
        }

        self::assertIsCallable($whenCallback, 'Expected a "required" rule covering newsletterListId with a "when" condition.');

        $enabled = new Settings();
        $enabled->newsletterSignupEnabled = true;
        self::assertTrue($whenCallback($enabled));

        $disabled = new Settings();
        $disabled->newsletterSignupEnabled = false;
        self::assertFalse($whenCallback($disabled));
    }

    public function testOnsiteTrackingIsDisabledByDefault(): void
    {
        $settings = new Settings();

        self::assertFalse($settings->onsiteTrackingEnabled);
    }

    public function testGetPublicApiKeyResolvesAnEnvVarReference(): void
    {
        putenv('COMMERCE_KLAVIYO_TEST_PUBLIC_KEY=AbCd12');
        $settings = new Settings();
        $settings->publicApiKey = '$COMMERCE_KLAVIYO_TEST_PUBLIC_KEY';

        self::assertSame('AbCd12', $settings->getPublicApiKey());

        putenv('COMMERCE_KLAVIYO_TEST_PUBLIC_KEY');
    }

    public function testPublicApiKeyIsOnlyRequiredWhenOnsiteTrackingIsEnabled(): void
    {
        $settings = new Settings();
        $reflection = new \ReflectionMethod($settings, 'defineRules');
        $reflection->setAccessible(true);

        $rules = $reflection->invoke($settings);

        $whenCallback = null;

        foreach ($rules as $rule) {
            if (in_array('publicApiKey', (array)$rule[0], true) && $rule[1] === 'required') {
                $whenCallback = $rule['when'] ?? null;
                break;
            }
        }

        self::assertIsCallable($whenCallback);

        $enabled = new Settings();
        $enabled->onsiteTrackingEnabled = true;
        self::assertTrue($whenCallback($enabled));

        $disabled = new Settings();
        $disabled->onsiteTrackingEnabled = false;
        self::assertFalse($whenCallback($disabled));
    }

    public function testBackInStockListSubscribeIsDisabledByDefault(): void
    {
        $settings = new Settings();

        self::assertFalse($settings->backInStockSubscribeToListEnabled);
        self::assertNull($settings->backInStockListId);
    }

    public function testBackInStockListIdIsOnlyRequiredWhenListSubscribeIsEnabled(): void
    {
        $settings = new Settings();
        $reflection = new \ReflectionMethod($settings, 'defineRules');
        $reflection->setAccessible(true);

        $rules = $reflection->invoke($settings);

        $whenCallback = null;

        foreach ($rules as $rule) {
            if (in_array('backInStockListId', (array)$rule[0], true) && $rule[1] === 'required') {
                $whenCallback = $rule['when'] ?? null;
                break;
            }
        }

        self::assertIsCallable($whenCallback);

        $enabled = new Settings();
        $enabled->backInStockSubscribeToListEnabled = true;
        self::assertTrue($whenCallback($enabled));

        $disabled = new Settings();
        $disabled->backInStockSubscribeToListEnabled = false;
        self::assertFalse($whenCallback($disabled));
    }
}
