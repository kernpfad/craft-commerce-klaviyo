<?php

namespace kernpfad\commerceklaviyo\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * @property-read array<string, string> $profileFieldMapping craftFieldHandle => klaviyoPropertyKey, decoded from profileFieldMappingRaw
 * @property-read array<string, string> $catalogFieldMapping craftFieldHandle => klaviyoMetadataKey, decoded from catalogFieldMappingTable
 */
class Settings extends Model
{
    /**
     * A private API key, or an env var reference (e.g. "$KLAVIYO_API_KEY") -
     * see {@see getApiKey()}, which resolves it. Stored as entered so a real
     * key never has to be committed to project config.
     */
    public ?string $apiKey = null;

    /**
     * Resolves {@see $apiKey} through Craft's env-var syntax, so a plain
     * "$KLAVIYO_API_KEY" in settings works the same as pasting the key
     * directly - the client (KlaviyoClient::__construct()) always needs the
     * literal key, never the "$VAR" reference.
     */
    public function getApiKey(): string
    {
        return (string)(App::parseEnv($this->apiKey) ?: '');
    }

    /**
     * The ID of the Yii application component all of this plugin's sync
     * jobs are pushed to — Craft's own `queue` component by default. Set
     * this to a different component ID (defined in `config/app.php`,
     * pointing at a real message-queue backend — SQS, RabbitMQ, Redis, via
     * one of yii2-queue's driver packages) to isolate Klaviyo sync traffic
     * from the rest of the site's queue jobs.
     */
    public string $queueComponentId = 'queue';

    /**
     * Commerce order status handles that should fire Klaviyo's standard
     * "Fulfilled Order" / "Cancelled Order" metrics. Commerce statuses are
     * merchant-defined, so there's no universal handle to assume.
     *
     * @var string[]
     */
    public array $fulfilledStatusHandles = [];

    /**
     * @var string[]
     */
    public array $cancelledStatusHandles = [];

    /**
     * When enabled (default), incomplete carts with an email fire Klaviyo's
     * `Updated Cart` metric when line items or the order total change —
     * not on every cart save (address-only updates are ignored).
     */
    public bool $trackUpdatedCart = true;

    /**
     * Handle of a custom field on products (checked on the variant first,
     * product as fallback) whose value becomes the Klaviyo catalog `title`.
     * When empty, the product's (or variant's) own native title is used —
     * matches this plugin's behavior before this setting existed.
     */
    public ?string $titleFieldHandle = null;

    /**
     * Handle of a custom field on products (and optionally variants) whose
     * value becomes the Klaviyo catalog `description`. When empty, the
     * product title is used instead.
     */
    public ?string $descriptionFieldHandle = null;

    /**
     * Handle of an Assets custom field on products (and optionally
     * variants) whose first asset URL becomes Klaviyo's `image_full_url`.
     * When empty, the image is omitted from the catalog payload.
     */
    public ?string $imageFieldHandle = null;

    /**
     * Handle of a Categories custom field on products whose selected
     * category elements are synced to Klaviyo as `catalog-category`
     * resources and linked to the catalog item. Each Craft category
     * element's own ID becomes the category's Klaviyo `external_id`, the
     * same convention as every other catalog resource here. When empty, no
     * categories are synced.
     */
    public ?string $categoriesFieldHandle = null;

    /**
     * Raw storage for the profile field mapping, as `handle1=property1`
     * lines (one per line) — kept as a plain string setting rather than a
     * DB table since it's small, admin-only config, not user data.
     */
    public string $profileFieldMappingRaw = '';

    /**
     * The catalog metadata field mapping, as editable-table rows —
     * `[['fieldHandle' => 'salePrice', 'klaviyoKey' => 'compare_at_price'], ...]`
     * — rather than a hand-typed string like {@see $profileFieldMappingRaw},
     * since the CP picks `fieldHandle` from a dropdown of the site's actual
     * product/variant fields (see {@see \kernpfad\commerceklaviyo\CommerceKlaviyo::settingsHtml()}),
     * so a typo can't silently produce a mapping that resolves nothing.
     *
     * Each mapped Craft field's value on the product (or the variant, when
     * it's set there instead — variant checked first, product as fallback)
     * is sent under `klaviyoKey` in Klaviyo's catalog item/variant
     * `custom_metadata` object (confirmed against Klaviyo's own Catalogs
     * API docs — NOT `metadata`, which doesn't exist and is rejected
     * outright), alongside the standard title/description/price/image
     * attributes. This is how project-specific data with no dedicated
     * catalog attribute — a strike-through/compare-at price, a promo
     * price, anything else — reaches Klaviyo without this plugin having to
     * grow a bespoke setting for every merchant's idea of "extra catalog
     * data".
     *
     * @var array<int, array{fieldHandle?: string, klaviyoKey?: string}>
     */
    public array $catalogFieldMappingTable = [];

    /**
     * Master toggle for newsletter-signup support. Off by default — this
     * plugin's core event/catalog/back-in-stock features work without it,
     * so it's opt-in rather than assumed.
     */
    public bool $newsletterSignupEnabled = false;

    /**
     * The Klaviyo List ID new newsletter subscribers are added to.
     */
    public ?string $newsletterListId = null;

    /**
     * If set, the ID of a Formie form ({@see https://formie.verbb.io}) whose
     * successful submissions are treated as newsletter signups — no need to
     * configure Formie's own, separate Klaviyo email-marketing integration
     * (and a second API key) just to reuse a Formie form for this. Left
     * null, this plugin's own built-in `newsletter/subscribe` form action is
     * the only signup path. Has no effect at all if Formie isn't installed.
     */
    public ?int $newsletterFormieFormId = null;

    /**
     * The handle of the field on the bound Formie form that holds the
     * subscriber's email address.
     */
    public string $newsletterFormieEmailFieldHandle = 'email';

    /**
     * @var string|null Handle of the bound Formie form's first-name field, if any.
     */
    public ?string $newsletterFormieFirstNameFieldHandle = null;

    /**
     * @var string|null Handle of the bound Formie form's last-name field, if any.
     */
    public ?string $newsletterFormieLastNameFieldHandle = null;

    /**
     * When enabled, {@see \kernpfad\commerceklaviyo\controllers\WebhookController}
     * accepts signed Klaviyo system-webhook POSTs and writes consent changes
     * to {@see $optOutFieldHandle} on matching Craft users.
     */
    public bool $webhookEnabled = false;

    /**
     * Klaviyo webhook signing secret, or an env var reference (e.g.
     * "$KLAVIYO_WEBHOOK_SECRET") — see {@see getWebhookSecret()}.
     */
    public ?string $webhookSecret = null;

    /**
     * Handle of a Lightswitch (or boolean) user field that stores whether
     * the customer has opted out of email marketing in Klaviyo.
     */
    public ?string $optOutFieldHandle = null;

    /**
     * When set to a positive integer N, tracked variants with stock above N
     * report a high placeholder quantity to Klaviyo instead of the real
     * stock. Real quantities are only sent once stock is at or below N —
     * so low-inventory and back-in-stock flows only see meaningful numbers
     * near the threshold, and high-stock catalog noise stays out of Klaviyo.
     * Empty / null / 0 disables the cap (always send the real stock).
     *
     * Untyped on purpose: Craft's settings form posts an empty string when
     * the number field is cleared; a `?int` property would TypeError before
     * validation can normalize it. {@see defineRules()} filters to `?int`.
     *
     * @var int|string|null
     */
    public $inventoryReportingThreshold = null;

    /**
     * When enabled, a successful back-in-stock signup also queues a Klaviyo
     * list subscription via {@see $backInStockListId}. Off by default —
     * many stores only want Klaviyo's own back-in-stock flow, not an extra
     * marketing list signup on the same form.
     */
    public bool $backInStockSubscribeToListEnabled = false;

    /**
     * Klaviyo list ID for optional back-in-stock list subscriptions.
     * Required when {@see $backInStockSubscribeToListEnabled} is on.
     */
    public ?string $backInStockListId = null;

    /**
     * Master toggle for Klaviyo onsite (JavaScript) tracking on the
     * storefront. Off by default — server-side catalog and order tracking
     * work without it. Requires {@see $publicApiKey}.
     */
    public bool $onsiteTrackingEnabled = false;

    /**
     * When enabled, anonymous Twig forms may POST to
     * `commerce-klaviyo/api/identify` and `commerce-klaviyo/api/track`
     * (Foster-style). Off by default — those endpoints accept profile
     * updates by email and can be abused if left open on public pages.
     */
    public bool $publicTrackActionsEnabled = false;

    /**
     * Klaviyo public API key (six-character site ID), or an env var reference
     * (e.g. "$KLAVIYO_PUBLIC_API_KEY") — see {@see getPublicApiKey()}.
     * Never the private API key.
     */
    public ?string $publicApiKey = null;

    public function getWebhookSecret(): string
    {
        return (string)(App::parseEnv($this->webhookSecret) ?: '');
    }

    public function getPublicApiKey(): string
    {
        return (string)(App::parseEnv($this->publicApiKey) ?: '');
    }

    /**
     * Normalizes the CP number-field value for {@see $inventoryReportingThreshold}
     * (empty string → null, numeric string → int). Used by validation and by
     * the catalog sync DI wiring.
     */
    public static function normalizeInventoryReportingThreshold(mixed $value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return (int)$value;
    }

    /**
     * @return array<string, string>
     */
    public function getProfileFieldMapping(): array
    {
        return $this->parseFieldMapping($this->profileFieldMappingRaw);
    }

    /**
     * @return array<string, string>
     */
    public function getCatalogFieldMapping(): array
    {
        $mapping = [];

        foreach ($this->catalogFieldMappingTable as $row) {
            $fieldHandle = trim((string)($row['fieldHandle'] ?? ''));
            $klaviyoKey = trim((string)($row['klaviyoKey'] ?? ''));

            if ($fieldHandle === '' || $klaviyoKey === '') {
                continue;
            }

            $mapping[$fieldHandle] = $klaviyoKey;
        }

        return $mapping;
    }

    /**
     * `handle1=property1` (one per line) parser behind
     * {@see getProfileFieldMapping()}.
     *
     * @return array<string, string>
     */
    private function parseFieldMapping(string $raw): array
    {
        $mapping = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            [$fieldHandle, $klaviyoProperty] = explode('=', $line, 2);
            $fieldHandle = trim($fieldHandle);
            $klaviyoProperty = trim($klaviyoProperty);

            if ($fieldHandle === '' || $klaviyoProperty === '') {
                continue;
            }

            $mapping[$fieldHandle] = $klaviyoProperty;
        }

        return $mapping;
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['apiKey'], 'string'],
            [['queueComponentId'], 'required'],
            [['queueComponentId'], 'string'],
            [['fulfilledStatusHandles', 'cancelledStatusHandles'], 'safe'],
            [['trackUpdatedCart'], 'boolean'],
            [['titleFieldHandle', 'descriptionFieldHandle', 'imageFieldHandle', 'categoriesFieldHandle'], 'string'],
            [['profileFieldMappingRaw'], 'string'],
            [['catalogFieldMappingTable'], 'safe'],
            [['newsletterSignupEnabled'], 'boolean'],
            [['newsletterListId'], 'required', 'when' => fn(self $model): bool => $model->newsletterSignupEnabled],
            [['newsletterListId', 'newsletterFormieEmailFieldHandle', 'newsletterFormieFirstNameFieldHandle', 'newsletterFormieLastNameFieldHandle'], 'string'],
            [['newsletterFormieFormId'], 'integer'],
            [['webhookEnabled'], 'boolean'],
            [['webhookSecret', 'optOutFieldHandle'], 'string'],
            [['optOutFieldHandle'], 'required', 'when' => fn(self $model): bool => $model->webhookEnabled],
            [['webhookSecret'], 'required', 'when' => fn(self $model): bool => $model->webhookEnabled],
            [['inventoryReportingThreshold'], 'filter', 'filter' => [self::class, 'normalizeInventoryReportingThreshold']],
            [['inventoryReportingThreshold'], 'integer', 'min' => 0, 'skipOnEmpty' => true],
            [['backInStockSubscribeToListEnabled'], 'boolean'],
            [['backInStockListId'], 'required', 'when' => fn(self $model): bool => $model->backInStockSubscribeToListEnabled],
            [['backInStockListId'], 'string'],
            [['onsiteTrackingEnabled'], 'boolean'],
            [['publicTrackActionsEnabled'], 'boolean'],
            [['publicApiKey'], 'string'],
            [['publicApiKey'], 'required', 'when' => fn(self $model): bool => $model->onsiteTrackingEnabled],
        ];
    }
}
