<?php

namespace kernpfad\commerceklaviyo\controllers;

use Craft;
use craft\web\Controller;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\services\ConsentSyncService;
use kernpfad\commerceklaviyo\services\WebhookVerificationService;
use yii\web\Response;

/**
 * Receives Klaviyo system-webhook POSTs for consent changes and writes
 * them to a configured local user field.
 */
class WebhookController extends Controller
{
    protected array|bool|int $allowAnonymous = ['receive'];

    public function beforeAction($action): bool
    {
        if ($action->id === 'receive') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    public function actionReceive(): Response
    {
        $plugin = CommerceKlaviyo::getInstance();

        if ($plugin === null || !$plugin->getSettings()->webhookEnabled) {
            return $this->asJson(['success' => false, 'message' => 'Webhook handling is disabled.']);
        }

        $secret = $plugin->getSettings()->getWebhookSecret();

        if ($secret === '') {
            Craft::warning('Commerce Klaviyo: webhook received but no secret is configured.', __METHOD__);

            return $this->asJson(['success' => false, 'message' => 'Webhook secret is not configured.']);
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof \craft\web\Request) {
            return $this->asJson(['success' => false, 'message' => 'Invalid request.']);
        }

        $rawBody = $request->getRawBody();
        $signature = (string)$request->getHeaders()->get('Klaviyo-Signature');
        $timestamp = (string)$request->getHeaders()->get('Klaviyo-Timestamp');
        $webhookId = (string)$request->getHeaders()->get('Klaviyo-Webhook-Id');

        if (!(new WebhookVerificationService())->verify($rawBody, $signature, $timestamp, $secret)) {
            Craft::warning('Commerce Klaviyo: rejected webhook with invalid signature.', __METHOD__);

            return $this->asJson(['success' => false, 'message' => 'Invalid signature.']);
        }

        $body = json_decode($rawBody, true);

        if (!is_array($body)) {
            return $this->asJson(['success' => false, 'message' => 'Invalid JSON body.']);
        }

        $metaWebhookId = $body['meta']['klaviyo_webhook_id'] ?? null;

        if (is_string($metaWebhookId) && $metaWebhookId !== '' && $webhookId !== '' && !hash_equals($metaWebhookId, $webhookId)) {
            Craft::warning('Commerce Klaviyo: webhook ID header/body mismatch.', __METHOD__);

            return $this->asJson(['success' => false, 'message' => 'Webhook ID mismatch.']);
        }

        $updated = (new ConsentSyncService())->applyWebhookBody(
            $body,
            (string)$plugin->getSettings()->optOutFieldHandle,
        );

        return $this->asJson(['success' => true, 'updated' => $updated]);
    }
}
