<?php

namespace kernpfad\commerceklaviyo\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;
use craft\helpers\Json;
use craft\web\Request;
use craft\web\View;
use kernpfad\commerceklaviyo\web\assets\onsite\OnsiteTrackingAsset;
use yii\base\Component;

/**
 * Registers optional Klaviyo onsite tracking on the storefront: loads
 * Klaviyo's public snippet, fires Viewed Product on product templates,
 * and surfaces Added to Cart payloads on Commerce cart AJAX responses
 * (plus a session fallback after non-AJAX form adds).
 */
class OnsiteTrackingService extends Component
{
    /** @var array<string, mixed>|null */
    private static ?array $pendingAddedToCart = null;

    /** @var array<string, mixed>|null */
    private ?array $viewedProductPayload = null;

    private bool $viewedProductCaptured = false;

    public function __construct(
        private readonly string $publicApiKey = '',
        private readonly ?string $descriptionFieldHandle = null,
        private readonly ?string $imageFieldHandle = null,
        private readonly OnsiteTrackingPayloadBuilder $payloadBuilder = new OnsiteTrackingPayloadBuilder(),
        $config = [],
    ) {
        parent::__construct($config);
    }

    public function isEnabled(): bool
    {
        return $this->publicApiKey !== '';
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function captureProductViewFromTemplateVariables(array $variables): void
    {
        if ($this->viewedProductCaptured || !$this->isEnabled()) {
            return;
        }

        $product = $variables['product'] ?? null;

        if (!$product instanceof Product) {
            return;
        }

        $variant = $variables['variant'] ?? null;

        if ($variant !== null && !$variant instanceof Variant) {
            $variant = null;
        }

        if ($variant === null) {
            $variant = $product->getDefaultVariant();
        }

        if (!$variant instanceof Variant) {
            return;
        }

        $payload = $this->payloadBuilder->buildViewedProduct(
            $product,
            $variant,
            $this->descriptionFieldHandle,
            $this->imageFieldHandle,
        );

        if ($payload === null) {
            return;
        }

        $this->viewedProductPayload = $payload;
        $this->viewedProductCaptured = true;
    }

    public function handleLineItemAdded(LineItem $lineItem, Order $cart): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $payload = $this->payloadBuilder->buildAddedToCart(
            $cart,
            $lineItem,
            $this->descriptionFieldHandle,
            $this->imageFieldHandle,
        );

        if ($payload === null) {
            return;
        }

        self::$pendingAddedToCart = $payload;

        $request = Craft::$app->getRequest();

        if ($request instanceof Request && !$request->getAcceptsJson()) {
            Craft::$app->getSession()->set('commerceKlaviyoAddedToCart', $payload);
        }
    }

    /**
     * @param array<string, mixed> $cartInfo
     * @return array<string, mixed>
     */
    public function appendCartTracking(array $cartInfo, Order $cart): array
    {
        if (!$this->isEnabled()) {
            return $cartInfo;
        }

        $payload = self::$pendingAddedToCart;

        if ($payload === null) {
            return $cartInfo;
        }

        $cartInfo['commerceKlaviyo'] = ['addedToCart' => $payload];
        self::$pendingAddedToCart = null;

        return $cartInfo;
    }

    public function registerStorefrontAssets(View $view): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $sessionPayload = Craft::$app->getSession()->get('commerceKlaviyoAddedToCart');
        Craft::$app->getSession()->remove('commerceKlaviyoAddedToCart');

        $config = [
            'publicApiKey' => $this->publicApiKey,
            'viewedProduct' => $this->viewedProductPayload,
            'pendingAddedToCart' => is_array($sessionPayload) ? $sessionPayload : null,
        ];

        $view->registerJs(
            'window.__commerceKlaviyoOnsite = ' . Json::encode($config) . ';',
            View::POS_HEAD,
        );
        $view->registerAssetBundle(OnsiteTrackingAsset::class);
    }

    /**
     * Resets static request state — for unit tests only.
     *
     * @internal
     */
    public static function resetRequestState(): void
    {
        self::$pendingAddedToCart = null;
    }
}
