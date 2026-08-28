<?php

namespace kernpfad\commerceklaviyo\controllers;

use Craft;
use craft\web\Controller;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use yii\web\Response;

/**
 * Public, anonymous-accessible newsletter signup — this plugin's own
 * built-in form action, for stores that don't use Formie (or don't want to
 * bind a Formie form to this). Disabled unless
 * {@see \kernpfad\commerceklaviyo\models\Settings::$newsletterSignupEnabled}
 * is on. Synchronous, matching {@see SubscriptionsController}'s back-in-stock
 * action and for the same reason: a customer-initiated form submission
 * outside checkout, not part of an order-processing critical path.
 */
class NewsletterController extends Controller
{
    protected int|bool|array $allowAnonymous = true;

    public function actionSubscribe(): ?Response
    {
        $this->requirePostRequest();

        $plugin = CommerceKlaviyo::getInstance();

        if ($plugin === null || !$plugin->getSettings()->newsletterSignupEnabled) {
            return $this->asFailure(Craft::t('commerce-klaviyo', 'Newsletter signup is not available right now.'));
        }

        $email = (string)$this->request->getRequiredBodyParam('email');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->asFailure(Craft::t('commerce-klaviyo', 'Please enter a valid email address.'));
        }

        $firstName = $this->request->getBodyParam('firstName');
        $lastName = $this->request->getBodyParam('lastName');

        $plugin->newsletterSubscription->subscribe(
            $email,
            is_string($firstName) && $firstName !== '' ? $firstName : null,
            is_string($lastName) && $lastName !== '' ? $lastName : null,
        );

        return $this->asSuccess(Craft::t('commerce-klaviyo', "You're subscribed!"));
    }
}
