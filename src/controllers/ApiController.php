<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\controllers;

use Craft;
use craft\web\Controller;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\services\TrackActionRequestParser;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Public Twig form actions for identify / track / list subscribe.
 * Disabled unless {@see Settings::$publicTrackActionsEnabled} is on.
 *
 * Warning: anonymous POSTs that accept arbitrary profile properties can be
 * abused to update existing Klaviyo profiles by email — keep the setting off
 * unless your templates need it, and never expose it on untrusted surfaces
 * without additional controls.
 */
class ApiController extends Controller
{
    protected int|bool|array $allowAnonymous = true;

    public function actionIdentify(): ?Response
    {
        return $this->runTrackAction(identifyOnly: true);
    }

    public function actionTrack(): ?Response
    {
        return $this->runTrackAction(identifyOnly: false);
    }

    private function runTrackAction(bool $identifyOnly): ?Response
    {
        $this->requirePostRequest();

        $plugin = CommerceKlaviyo::getInstance();
        if ($plugin === null || !$plugin->getSettings()->publicTrackActionsEnabled) {
            throw new BadRequestHttpException(Craft::t(
                'commerce-klaviyo',
                'Public track actions are disabled.',
            ));
        }

        $parsed = (new TrackActionRequestParser())->parse($this->request->getBodyParams());
        if ($identifyOnly) {
            $parsed = new \kernpfad\commerceklaviyo\models\TrackActionRequest(
                email: $parsed->email,
                profile: $parsed->profile,
                eventName: null,
                eventUniqueId: null,
                eventValue: null,
                eventValueCurrency: null,
                eventProperties: [],
                listIds: [],
                subscribe: false,
                trackOrder: false,
                orderId: null,
                forward: $parsed->forward,
            );
        }

        if ($parsed->email === null) {
            return $this->asFailure(Craft::t('commerce-klaviyo', 'Please enter a valid email address.'));
        }

        try {
            $plugin->trackActions->handle($parsed);
        } catch (\Throwable $e) {
            Craft::error('Commerce Klaviyo track action failed: ' . $e->getMessage(), 'commerce-klaviyo');
        }

        if ($this->request->getAcceptsJson() && $parsed->forward === null) {
            return $this->asSuccess(Craft::t('commerce-klaviyo', 'OK'));
        }

        if ($parsed->forward !== null) {
            return $this->redirect($parsed->forward);
        }

        return $this->redirectToPostedUrl();
    }
}
