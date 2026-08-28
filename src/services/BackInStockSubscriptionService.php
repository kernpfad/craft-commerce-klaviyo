<?php

namespace kernpfad\commerceklaviyo\services;

use craft\commerce\elements\Variant;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\events\BuildBackInStockPayloadEvent;
use yii\base\Component;

/**
 * Orchestrates a back-in-stock signup: stock guard, Klaviyo subscription,
 * optional list subscribe, and customer-safe error messages.
 */
class BackInStockSubscriptionService extends Component
{
    /**
     * @param array<int|string, string> $errorMessages optional overrides for
     *     {@see KlaviyoApiErrorFormatter}
     */
    public function __construct(
        private readonly ?string $listId = null,
        private readonly bool $subscribeToList = false,
        private readonly ?NewsletterSubscriptionService $newsletterSubscription = null,
        private readonly array $errorMessages = [],
        $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function subscribe(Variant $variant, string $email, KlaviyoClient $client): array
    {
        if (!BackInStockGuard::isEligible($variant->inventoryTracked, $variant->getStock())) {
            if (!$variant->inventoryTracked) {
                return [
                    'success' => false,
                    'message' => 'Back-in-stock notifications are not available for this product.',
                ];
            }

            return [
                'success' => false,
                'message' => 'This item is currently in stock — back-in-stock notifications are only for sold-out variants.',
            ];
        }

        $payload = (new CatalogPayloadBuilder())->buildBackInStockSubscription((string)$variant->id, $email);

        $payload = (new PayloadEventDispatcher())->dispatch(
            CommerceKlaviyo::EVENT_BEFORE_BUILD_BACK_IN_STOCK_PAYLOAD,
            new BuildBackInStockPayloadEvent($variant, $email, $payload),
        );

        $formatter = new KlaviyoApiErrorFormatter();

        try {
            $client->post('back-in-stock-subscriptions', $payload);
        } catch (ClientException $e) {
            return [
                'success' => false,
                'message' => $formatter->formatClientException($e, $this->errorMessages),
            ];
        } catch (GuzzleException) {
            return [
                'success' => false,
                'message' => $this->errorMessages['default']
                    ?? "Couldn't save your subscription. Please try again.",
            ];
        }

        if ($this->subscribeToList && $this->listId !== null && $this->listId !== '') {
            $this->newsletterSubscription?->subscribe($email, listId: $this->listId);
        }

        return [
            'success' => true,
            'message' => "You'll be notified when this is back in stock.",
        ];
    }
}
