<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\utilities;

use Craft;
use craft\base\Utility;
use kernpfad\commerceklaviyo\CommerceKlaviyo;

/**
 * CP utility to queue historical Placed Order events for a date range.
 */
class HistoricalOrdersUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('commerce-klaviyo', 'Klaviyo historical orders');
    }

    public static function id(): string
    {
        return 'commerce-klaviyo-historical-orders';
    }

    public static function icon(): ?string
    {
        return 'clock-rotate-left';
    }

    public static function contentHtml(): string
    {
        $plugin = CommerceKlaviyo::getInstance();

        return Craft::$app->getView()->renderTemplate('commerce-klaviyo/utilities/historical-orders', [
            'configured' => $plugin !== null && $plugin->getKlaviyoClient() !== null,
        ]);
    }
}
