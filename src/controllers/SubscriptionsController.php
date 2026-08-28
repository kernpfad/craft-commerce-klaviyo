<?php

namespace kernpfad\commerceklaviyo\controllers;

use Craft;
use craft\commerce\elements\Variant;
use craft\web\Controller;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use yii\web\Response;

/**
 * Public, anonymous-accessible back-in-stock subscription. Synchronous
 * (not queued), unlike the rest of this plugin's Klaviyo calls — this is a
 * customer-initiated form submission outside the checkout flow, not part
 * of an order-processing critical path, so a customer waiting a few
 * hundred milliseconds for a "you're subscribed" confirmation (or a clear
 * failure message to retry) is normal, expected form-submission UX.
 */
class SubscriptionsController extends Controller
{
    protected int|bool|array $allowAnonymous = true;

    public function actionBackInStock(): ?Response
    {
        $this->requirePostRequest();

        $variantId = (int)$this->request->getRequiredBodyParam('variantId');
        $email = (string)$this->request->getRequiredBodyParam('email');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->asFailure(Craft::t('commerce-klaviyo', 'Please enter a valid email address.'));
        }

        $variant = Variant::find()->id($variantId)->status(null)->one();

        if (!$variant instanceof Variant) {
            return $this->asFailure(Craft::t('commerce-klaviyo', 'That product could not be found.'));
        }

        $plugin = CommerceKlaviyo::getInstance();

        if ($plugin === null) {
            return $this->asFailure(Craft::t('commerce-klaviyo', 'Back-in-stock notifications are not available right now.'));
        }

        $client = $plugin->getKlaviyoClient();

        if ($client === null) {
            return $this->asFailure(Craft::t('commerce-klaviyo', 'Back-in-stock notifications are not available right now.'));
        }

        $result = $plugin->backInStockSubscription->subscribe($variant, $email, $client);

        if (!$result['success']) {
            Craft::error(
                "Commerce Klaviyo: back-in-stock subscription rejected for variant #{$variant->id}: {$result['message']}",
                __METHOD__,
            );

            return $this->asFailure(Craft::t('commerce-klaviyo', $result['message']));
        }

        return $this->asSuccess(Craft::t('commerce-klaviyo', $result['message']));
    }
}
