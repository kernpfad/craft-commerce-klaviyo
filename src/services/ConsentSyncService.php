<?php

namespace kernpfad\commerceklaviyo\services;

use Craft;
use craft\elements\User;

/**
 * Applies Klaviyo consent/unsubscribe webhook events to a local Craft user
 * field so the store can respect marketing opt-outs outside Klaviyo.
 */
class ConsentSyncService
{
    public function __construct(
        private readonly WebhookPayloadParser $parser = new WebhookPayloadParser(),
    ) {
    }

    /**
     * @param array<string, mixed> $body decoded JSON webhook body
     * @return int number of Craft users updated
     */
    public function applyWebhookBody(array $body, string $optOutFieldHandle): int
    {
        if ($optOutFieldHandle === '') {
            return 0;
        }

        $updated = 0;

        foreach ($this->parser->parseConsentChanges($body) as $change) {
            if ($this->applyToUser($change['email'], $change['optedOut'], $optOutFieldHandle)) {
                $updated++;
            }
        }

        return $updated;
    }

    public function applyToUser(string $email, bool $optedOut, string $optOutFieldHandle): bool
    {
        if ($optOutFieldHandle === '') {
            return false;
        }

        $user = User::find()->email($email)->one();

        if (!$user instanceof User) {
            Craft::info("Commerce Klaviyo: no Craft user found for webhook email {$email}.", __METHOD__);

            return false;
        }

        $user->setFieldValue($optOutFieldHandle, $optedOut);

        if (!Craft::$app->getElements()->saveElement($user)) {
            Craft::error(
                'Commerce Klaviyo: failed to save opt-out field for user #' . $user->id . ': '
                . implode(', ', $user->getErrorSummary(true)),
                __METHOD__,
            );

            return false;
        }

        return true;
    }
}
