<?php

namespace kernpfad\commerceklaviyo;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\commerce\controllers\BaseFrontEndController;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\events\InventoryMovementEvent;
use craft\commerce\events\LineItemEvent;
use craft\commerce\events\ModifyCartInfoEvent;
use craft\commerce\events\OrderStatusEvent;
use craft\commerce\events\RefundTransactionEvent;
use craft\commerce\services\Inventory;
use craft\commerce\services\OrderHistories;
use craft\commerce\services\Payments;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\TemplateEvent;
use craft\services\Utilities;
use craft\web\View;
use kernpfad\commerceklaviyo\models\Settings;
use kernpfad\commerceklaviyo\services\BackInStockSubscriptionService;
use kernpfad\commerceklaviyo\services\CatalogSyncService;
use kernpfad\commerceklaviyo\services\HistoricalOrderSyncService;
use kernpfad\commerceklaviyo\services\KlaviyoClient;
use kernpfad\commerceklaviyo\services\KlaviyoListsService;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;
use kernpfad\commerceklaviyo\services\NewsletterSubscriptionService;
use kernpfad\commerceklaviyo\services\OnsiteTrackingService;
use kernpfad\commerceklaviyo\services\OrderTrackingService;
use kernpfad\commerceklaviyo\services\ProfileMapper;
use kernpfad\commerceklaviyo\utilities\HistoricalOrdersUtility;
use verbb\formie\elements\Form as FormieForm;
use verbb\formie\events\SubmissionEvent as FormieSubmissionEvent;
use verbb\formie\services\Submissions as FormieSubmissions;
use yii\base\Event;
use yii\queue\Queue as YiiQueue;

/**
 * @property CatalogSyncService $catalogSync
 * @property OrderTrackingService $orderTracking
 * @property NewsletterSubscriptionService $newsletterSubscription
 * @property BackInStockSubscriptionService $backInStockSubscription
 * @property HistoricalOrderSyncService $historicalOrderSync
 * @method Settings getSettings()
 */
class CommerceKlaviyo extends Plugin
{
    public const EVENT_BEFORE_BUILD_CATALOG_ITEM_PAYLOAD = 'beforeBuildCatalogItemPayload';
    public const EVENT_BEFORE_BUILD_CATALOG_VARIANT_PAYLOAD = 'beforeBuildCatalogVariantPayload';
    public const EVENT_BEFORE_BUILD_CATALOG_INVENTORY_PAYLOAD = 'beforeBuildCatalogInventoryPayload';
    public const EVENT_BEFORE_BUILD_BACK_IN_STOCK_PAYLOAD = 'beforeBuildBackInStockPayload';
    public const EVENT_BEFORE_BUILD_TRACK_EVENT_PAYLOAD = 'beforeBuildTrackEventPayload';
    public const EVENT_BEFORE_BUILD_NEWSLETTER_PAYLOAD = 'beforeBuildNewsletterPayload';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = false;
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        // Console commands (reindex, connection test) live in a separate
        // namespace from the CP settings controller, and are only relevant
        // for console requests.
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'kernpfad\\commerceklaviyo\\console\\controllers';
        }

        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = HistoricalOrdersUtility::class;
            }
        );

        $this->set('catalogSync', function() {
            $settings = $this->getSettings();

            return new CatalogSyncService(
                titleFieldHandle: $settings->titleFieldHandle,
                descriptionFieldHandle: $settings->descriptionFieldHandle,
                imageFieldHandle: $settings->imageFieldHandle,
                categoriesFieldHandle: $settings->categoriesFieldHandle,
                catalogFieldMapping: $settings->getCatalogFieldMapping(),
                inventoryReportingThreshold: Settings::normalizeInventoryReportingThreshold(
                    $settings->inventoryReportingThreshold
                ),
                queue: $this->getSyncQueue(),
            );
        });

        $this->set('orderTracking', function() {
            return new OrderTrackingService(
                profileFieldMapping: $this->getSettings()->getProfileFieldMapping(),
                queue: $this->getSyncQueue(),
            );
        });

        $this->set('newsletterSubscription', function() {
            return new NewsletterSubscriptionService(
                listId: $this->getSettings()->newsletterListId,
                queue: $this->getSyncQueue(),
            );
        });

        $this->set('historicalOrderSync', function() {
            return new HistoricalOrderSyncService(
                queue: $this->getSyncQueue(),
            );
        });

        $this->set('backInStockSubscription', function() {
            $settings = $this->getSettings();

            return new BackInStockSubscriptionService(
                listId: $settings->backInStockListId,
                subscribeToList: $settings->backInStockSubscribeToListEnabled,
                newsletterSubscription: $this->newsletterSubscription,
            );
        });

        Event::on(
            Order::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Order $order */
                $order = $event->sender;

                if (!$order->isCompleted) {
                    $this->orderTracking->trackStartedCheckout($order);
                }
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            function(Event $event) {
                /** @var Order $order */
                $order = $event->sender;
                $this->orderTracking->trackPlacedOrder($order);
            }
        );

        Event::on(
            OrderHistories::class,
            OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            function(OrderStatusEvent $event) {
                if ($event->orderHistory->prevStatusId === $event->orderHistory->newStatusId) {
                    return;
                }

                $newStatusId = $event->orderHistory->newStatusId;
                $newStatus = $newStatusId !== null
                    ? \craft\commerce\Plugin::getInstance()?->getOrderStatuses()->getOrderStatusById($newStatusId)
                    : null;

                if ($newStatus === null || $newStatus->handle === null) {
                    return;
                }

                $this->orderTracking->trackStatusChange(
                    $event->order,
                    $newStatus->handle,
                    $this->getSettings()->fulfilledStatusHandles,
                    $this->getSettings()->cancelledStatusHandles,
                );
            }
        );

        Event::on(
            Payments::class,
            Payments::EVENT_AFTER_REFUND_TRANSACTION,
            function(RefundTransactionEvent $event) {
                $order = $event->transaction->getOrder();

                if ($order !== null) {
                    $this->orderTracking->trackRefund($order, $event->refundTransaction);
                }
            }
        );

        Event::on(
            Product::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Product $product */
                $product = $event->sender;
                $this->catalogSync->syncProduct($product);
            }
        );

        // Deliberately on Variant, not Product: verified against a real
        // save that a product's variants aren't persisted yet (no id, and
        // a fresh DB query finds nothing) at the moment Product::EVENT_AFTER_SAVE
        // fires — Commerce saves them as their own, later element saves. A
        // variant's *own* EVENT_AFTER_SAVE is the reliable point its id and
        // getProduct() are both actually available. See CatalogSyncService's
        // class docblock.
        Event::on(
            Variant::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Variant $variant */
                $variant = $event->sender;
                $this->catalogSync->syncVariant($variant);
            }
        );

        // Removing one variant from a still-existing product. A whole-product
        // delete is handled below instead, where Klaviyo cascades variant
        // deletion from the parent item on its own.
        Event::on(
            Variant::class,
            Element::EVENT_AFTER_DELETE,
            function(Event $event) {
                /** @var Variant $variant */
                $variant = $event->sender;
                $this->catalogSync->deleteVariant($variant);
            }
        );

        Event::on(
            Product::class,
            Element::EVENT_AFTER_DELETE,
            function(Event $event) {
                /** @var Product $product */
                $product = $event->sender;
                $this->catalogSync->deleteProduct($product);
            }
        );

        Event::on(
            Inventory::class,
            Inventory::EVENT_AFTER_EXECUTE_INVENTORY_MOVEMENT,
            function(InventoryMovementEvent $event) {
                $variant = $event->inventoryMovement->getInventoryItem()?->getPurchasable();

                if ($variant instanceof Variant) {
                    $this->catalogSync->syncVariantInventory($variant);
                }
            }
        );

        // Formie support is entirely optional — verbb/formie isn't a
        // Composer dependency of this plugin, so this listener is only
        // ever wired up if it's actually installed. Mirrors the same
        // class_exists() guard Formie itself uses for its own optional
        // Feed Me integration.
        if (class_exists(FormieSubmissions::class)) {
            Event::on(
                FormieSubmissions::class,
                FormieSubmissions::EVENT_AFTER_SUBMISSION,
                function(FormieSubmissionEvent $event) {
                    $this->handleFormieSubmission($event);
                }
            );
        }

        $this->registerOnsiteTracking();
    }

    private function registerOnsiteTracking(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest() || Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        $settings = $this->getSettings();

        if (!$settings->onsiteTrackingEnabled) {
            return;
        }

        $onsiteTracking = new OnsiteTrackingService(
            publicApiKey: $settings->getPublicApiKey(),
            descriptionFieldHandle: $settings->descriptionFieldHandle,
            imageFieldHandle: $settings->imageFieldHandle,
        );

        if (!$onsiteTracking->isEnabled()) {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_TEMPLATE,
            function(TemplateEvent $event) use ($onsiteTracking) {
                $onsiteTracking->captureProductViewFromTemplateVariables($event->variables);
            }
        );

        Event::on(
            View::class,
            View::EVENT_END_BODY,
            function() use ($onsiteTracking) {
                $onsiteTracking->registerStorefrontAssets(Craft::$app->getView());
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_AFTER_ADD_LINE_ITEM,
            function(LineItemEvent $event) use ($onsiteTracking) {
                if (!$event->isNew) {
                    return;
                }

                /** @var Order $cart */
                $cart = $event->sender;
                $onsiteTracking->handleLineItemAdded($event->lineItem, $cart);
            }
        );

        Event::on(
            BaseFrontEndController::class,
            BaseFrontEndController::EVENT_MODIFY_CART_INFO,
            function(ModifyCartInfoEvent $event) use ($onsiteTracking) {
                if ($event->cart === null) {
                    return;
                }

                $event->cartInfo = $onsiteTracking->appendCartTracking($event->cartInfo, $event->cart);
            }
        );
    }

    private function handleFormieSubmission(FormieSubmissionEvent $event): void
    {
        $settings = $this->getSettings();

        if (!$settings->newsletterSignupEnabled || $settings->newsletterFormieFormId === null) {
            return;
        }

        $submission = $event->submission;

        if ($submission === null || !$event->success || $submission->isIncomplete) {
            return;
        }

        if ((int)$submission->getForm()?->id !== $settings->newsletterFormieFormId) {
            return;
        }

        $email = $submission->getFieldValue($settings->newsletterFormieEmailFieldHandle);

        if (!is_string($email) || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $firstName = $settings->newsletterFormieFirstNameFieldHandle !== null
            ? $submission->getFieldValue($settings->newsletterFormieFirstNameFieldHandle)
            : null;
        $lastName = $settings->newsletterFormieLastNameFieldHandle !== null
            ? $submission->getFieldValue($settings->newsletterFormieLastNameFieldHandle)
            : null;

        $profileFieldMapping = $settings->getProfileFieldMapping();
        $formFieldValues = [];

        foreach (array_keys($profileFieldMapping) as $fieldHandle) {
            $formFieldValues[$fieldHandle] = $submission->getFieldValue($fieldHandle);
        }

        $properties = (new ProfileMapper())->mapProperties($profileFieldMapping, $formFieldValues);

        $this->newsletterSubscription->subscribe(
            $email,
            is_string($firstName) && $firstName !== '' ? $firstName : null,
            is_string($lastName) && $lastName !== '' ? $lastName : null,
            $properties,
        );
    }

    /**
     * Resolves the Yii application component this plugin's sync jobs are
     * pushed to, per {@see Settings::$queueComponentId}. Falls back to
     * Craft's own default `queue` component — logged, not thrown — if the
     * configured component ID doesn't exist or isn't actually a queue, so
     * a typo in a config file can never break a product save or checkout.
     */
    public function getSyncQueue(): YiiQueue
    {
        $componentId = $this->getSettings()->queueComponentId;
        $component = $componentId !== 'queue' ? Craft::$app->get($componentId, false) : null;

        if ($component instanceof YiiQueue) {
            return $component;
        }

        if ($componentId !== 'queue') {
            Craft::warning(
                "Commerce Klaviyo: configured queue component \"{$componentId}\" is not available; falling back to the default queue.",
                __METHOD__
            );
        }

        return Craft::$app->getQueue();
    }

    public function getKlaviyoClient(): ?KlaviyoClient
    {
        $apiKey = $this->getSettings()->getApiKey();

        if ($apiKey === '') {
            return null;
        }

        return new KlaviyoClient($apiKey);
    }

    /**
     * A single lightweight, read-only call used to verify the configured
     * API key actually works, without side effects on the Klaviyo account.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        $client = $this->getKlaviyoClient();

        if ($client === null) {
            return ['success' => false, 'message' => Craft::t('commerce-klaviyo', 'No API key configured.')];
        }

        try {
            $client->get('accounts/');
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'message' => Craft::t('commerce-klaviyo', 'Connection successful.')];
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $status = new KlaviyoStatusService();
        $settings = $this->getSettings();
        $selectedListOptIn = $settings->newsletterListId !== null && $settings->newsletterListId !== ''
            ? (new KlaviyoListsService($this->getKlaviyoClient()))->getOptInProcessForListSafely($settings->newsletterListId)
            : null;
        $selectedBackInStockListOptIn = $settings->backInStockListId !== null && $settings->backInStockListId !== ''
            ? (new KlaviyoListsService($this->getKlaviyoClient()))->getOptInProcessForListSafely($settings->backInStockListId)
            : null;

        return Craft::$app->getView()->renderTemplate('commerce-klaviyo/settings.twig', [
            'settings' => $settings,
            'orderStatuses' => \craft\commerce\Plugin::getInstance()?->getOrderStatuses()->getAllOrderStatuses() ?? [],
            'formieForms' => class_exists(FormieForm::class) ? FormieForm::find()->all() : [],
            'lastCatalogError' => $status->getCatalogError(),
            'lastTrackError' => $status->getTrackError(),
            'lastCatalogSuccess' => $status->getLastCatalogSuccess(),
            'lastReindex' => $status->getLastReindex(),
            'lastBulkJob' => $status->getLastBulkJob(),
            'webhookUrl' => Craft::$app->getSites()->getPrimarySite()->getBaseUrl() . 'actions/commerce-klaviyo/webhook/receive',
            'backInStockAction' => 'commerce-klaviyo/subscriptions/back-in-stock',
            'selectedListOptInProcess' => $selectedListOptIn,
            'selectedBackInStockListOptInProcess' => $selectedBackInStockListOptIn,
            'catalogFieldOptions' => $this->getCatalogFieldOptions(),
            'catalogImageFieldOptions' => $this->getCatalogFieldOptions(fn(\craft\base\FieldInterface $field): bool => $field instanceof \craft\fields\Assets),
            'catalogCategoriesFieldOptions' => $this->getCatalogFieldOptions(fn(\craft\base\FieldInterface $field): bool => $field instanceof \craft\fields\Categories),
            'catalogMetadataFieldOptions' => $this->getCatalogMetadataFieldOptions(),
        ]);
    }

    /**
     * Every custom field on any product type's product or variant field
     * layout — shared traversal behind every `getCatalog*FieldOptions()`
     * method, de-duplicated by handle (the same field reused across
     * product types, or shared between a product and its variants, should
     * only appear once).
     *
     * @return array<string, \craft\base\FieldInterface>
     */
    private function collectCustomFields(): array
    {
        $productTypes = \craft\commerce\Plugin::getInstance()?->getProductTypes()->getAllProductTypes() ?? [];
        $fields = [];

        foreach ($productTypes as $productType) {
            foreach ([$productType->getProductFieldLayout(), $productType->getVariantFieldLayout()] as $layout) {
                foreach ($layout->getCustomFields() as $field) {
                    $handle = $field->handle;
                    if (!is_string($handle) || $handle === '') {
                        continue;
                    }
                    $fields[$handle] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * @param array<string, string> $options handle/value => label
     * @return array<int, array{label: string, value: string}>
     */
    private function toSortedOptions(array $options): array
    {
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        $result = [];

        foreach ($options as $value => $label) {
            $result[] = ['label' => $label, 'value' => $value];
        }

        return $result;
    }

    /**
     * Every custom field matching `$filter` (or every field at all, when
     * omitted), for the title/description/image/categories field-mapping
     * dropdowns in `settings.twig` — a select rather than a hand-typed
     * handle, so a typo can't produce a mapping that silently resolves
     * nothing.
     *
     * @param ?callable(\craft\base\FieldInterface): bool $filter
     * @return array<int, array{label: string, value: string}>
     */
    private function getCatalogFieldOptions(?callable $filter = null): array
    {
        $options = [];

        foreach ($this->collectCustomFields() as $handle => $field) {
            if ($filter === null || $filter($field)) {
                $options[$handle] = "{$field->name} ({$handle})";
            }
        }

        return $this->toSortedOptions($options);
    }

    /**
     * Same field list as {@see getCatalogFieldOptions()}, but a field with
     * no single scalar value of its own offers flattened variants instead
     * of the bare handle: a relation field (Categories, Entries, Tags,
     * Assets, ...) offers `handle.id`/`handle.title`, an options field
     * (Dropdown, Radio Buttons, Checkboxes, Multi-select) offers
     * `handle.value`/`handle.label`. Unlike title, description, or the
     * image field (each expecting one string/URL), a metadata entry's key
     * is merchant-chosen, so there's no reason to limit it to scalar
     * fields the way the other dropdowns do — but the bare handle of
     * either field kind would still silently resolve to nothing (see
     * {@see \kernpfad\commerceklaviyo\services\CatalogFieldResolver::resolveValue()}),
     * so it's deliberately never offered as an option; only the flattened
     * forms {@see \kernpfad\commerceklaviyo\services\CatalogFieldResolver}
     * actually knows how to resolve are.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function getCatalogMetadataFieldOptions(): array
    {
        $options = [];

        foreach ($this->collectCustomFields() as $handle => $field) {
            if ($field instanceof \craft\fields\BaseRelationField) {
                $options["{$handle}.id"] = "{$field->name} ({$handle}.id)";
                $options["{$handle}.title"] = "{$field->name} ({$handle}.title)";

                continue;
            }

            if ($field instanceof \craft\fields\BaseOptionsField) {
                $options["{$handle}.value"] = "{$field->name} ({$handle}.value)";
                $options["{$handle}.label"] = "{$field->name} ({$handle}.label)";

                continue;
            }

            $options[$handle] = "{$field->name} ({$handle})";
        }

        return $this->toSortedOptions($options);
    }
}
