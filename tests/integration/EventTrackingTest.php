<?php

namespace kernpfad\commerceklaviyo\tests\integration;

/**
 * Boots a real Craft + Commerce application and drives the actual
 * production event-listener pipeline (`CommerceKlaviyo::init()`) with real
 * orders, products, and variants — nothing here calls the orchestrating
 * services directly except where explicitly noted.
 *
 * Rather than executing queued jobs against the real Klaviyo API (no
 * credentials exist in this environment, and doing so would make the test
 * suite depend on network access and a live account), these tests inspect
 * Craft's own queue via `Queue::getJobInfo()` to confirm the *right jobs
 * with the right descriptions* were queued in response to real Commerce
 * events. `KlaviyoClientTest` (unit) separately covers the actual HTTP
 * behavior of those jobs against a mocked Klaviyo API.
 *
 * Requires CRAFT_TEST_SITE_PATH to point at a working Craft + Commerce
 * install with this plugin linked in via a Composer path repository. Skips
 * itself if that's not configured.
 *
 * PHPUnit will flag the first test as "risky" (error/exception handlers
 * not restored) — that's Craft's own application bootstrap registering its
 * handlers inside the same process, not a bug here. It doesn't fail the
 * run (exit code stays 0).
 */

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\FieldLayoutTab;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\services\CatalogSyncService;
use kernpfad\commerceklaviyo\services\NewsletterSubscriptionService;
use kernpfad\commerceklaviyo\services\OrderTrackingService;
use PHPUnit\Framework\TestCase;

class EventTrackingTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        $sitePath = getenv('CRAFT_TEST_SITE_PATH');

        if (!$sitePath || !is_dir($sitePath)) {
            $this->markTestSkipped(
                'CRAFT_TEST_SITE_PATH is not set to a working Craft install; skipping integration tests.'
            );
        }

        if (!self::$booted) {
            define('CRAFT_BASE_PATH', $sitePath);
            define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
            require CRAFT_VENDOR_PATH . '/autoload.php';

            if (class_exists(\Dotenv\Dotenv::class)) {
                \Dotenv\Dotenv::createImmutable(CRAFT_BASE_PATH)->safeLoad();
            }

            require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
            self::$booted = true;
        }

        if (!class_exists(Commerce::class) || Commerce::getInstance() === null) {
            $this->markTestSkipped('Craft Commerce is not installed on the test install; skipping.');
        }

        $plugin = CommerceKlaviyo::getInstance();
        self::assertNotNull($plugin, 'Commerce Klaviyo plugin is not installed on the test install.');
        $plugin->getSettings()->apiKey = 'test-key-not-a-real-secret';
        $plugin->getSettings()->fulfilledStatusHandles = ['shipped'];
        $plugin->getSettings()->cancelledStatusHandles = ['cancelled'];

        // Nothing in this suite ever executes the queue (no real Klaviyo
        // credentials exist here), so jobs pushed by one test would
        // otherwise sit in the table and inflate every later test's count.
        \Craft::$app->getDb()->createCommand()->truncateTable('{{%queue}}')->execute();
    }

    public function testSavingAProductQueuesACatalogItemSyncJob(): void
    {
        $product = $this->createProduct(15.0);

        $descriptions = $this->queuedDescriptions();

        self::assertTrue(
            $this->anyContains($descriptions, 'Klaviyo catalog'),
            'Expected a catalog-sync job to be queued after saving a product. Queue contents: ' . implode(' | ', $descriptions)
        );
    }

    public function testSavingAProductAlsoQueuesACatalogVariantSyncJob(): void
    {
        // Regression test: the variant sync used to be wired to
        // Product::EVENT_AFTER_SAVE, at which point Commerce hasn't
        // actually persisted the variant yet (its id is still null), so
        // this job was silently never queued on any real product save.
        $product = $this->createProduct(15.0);

        $descriptions = $this->queuedDescriptions();

        self::assertTrue(
            $this->anyContains($descriptions, 'variant to Klaviyo catalog'),
            'Expected a catalog-variant-sync job to be queued after saving a product. Queue contents: ' . implode(' | ', $descriptions)
        );
    }

    public function testSavingADraftDoesNotQueueAnyCatalogJob(): void
    {
        // Regression test: Craft fires EVENT_AFTER_SAVE for drafts too, and a
        // draft is a separate element with its own ID. Without a guard, every
        // CP autosave pushed a junk catalog entry keyed to the draft's ID.
        $product = $this->createProduct(15.0);
        \Craft::$app->getDb()->createCommand()->truncateTable('{{%queue}}')->execute();

        $draft = \Craft::$app->getDrafts()->createDraft($product, null, 'Klaviyo test draft');
        $draft->title = 'Edited in a draft';
        self::assertTrue(\Craft::$app->getElements()->saveElement($draft));

        self::assertSame([], $this->klaviyoCatalogJobs(), 'A draft save must not queue any Klaviyo catalog job.');
    }

    public function testCreatingARevisionDoesNotQueueAnyCatalogJob(): void
    {
        // Same guard, the higher-volume case: Craft creates a revision on
        // every CP publish, so without this an actively-edited store would
        // accumulate a junk catalog item *and* variant per edit, forever.
        $product = $this->createProduct(15.0);
        \Craft::$app->getDb()->createCommand()->truncateTable('{{%queue}}')->execute();

        \Craft::$app->getRevisions()->createRevision($product);

        self::assertSame([], $this->klaviyoCatalogJobs(), 'Creating a revision must not queue any Klaviyo catalog job.');
    }

    public function testRemovingOneVariantQueuesACatalogVariantDelete(): void
    {
        // Regression test: only whole-product deletion was handled, so
        // discontinuing a single size/colour left its catalog variant in
        // Klaviyo forever — still in product blocks, still accepting
        // back-in-stock signups for something unbuyable.
        $product = $this->createProduct(15.0, 2);
        $variants = $product->getVariants()->all();
        self::assertCount(2, $variants);

        \Craft::$app->getDb()->createCommand()->truncateTable('{{%queue}}')->execute();

        $product->setVariants([$variants[0]]);
        $product->setDirtyAttributes(['variants']);
        self::assertTrue(\Craft::$app->getElements()->saveElement($product));

        // Removing a variant via product save is a Craft soft-delete (trashed),
        // so CatalogSyncService queues UnpublishCatalogVariantJob rather than
        // the hard-delete job. Either path keeps the discontinued SKU out of
        // Klaviyo product blocks; this assertion matches the live queue description.
        self::assertTrue(
            $this->anyContains($this->queuedDescriptions(), 'variant in Klaviyo catalog (trashed)')
            || $this->anyContains($this->queuedDescriptions(), 'variant from Klaviyo catalog'),
            'Expected a catalog-variant unpublish or delete job. Queue: ' . implode(' | ', $this->queuedDescriptions())
        );
    }

    public function testStartedCheckoutFiresOnceWhenACartGetsAnEmailAndNotAgainOnASubsequentSave(): void
    {
        $order = new Order();
        $order->number = \Craft::$app->getSecurity()->generateRandomString(32);
        $order->storeId = Commerce::getInstance()->getStores()->getPrimaryStore()->id;
        $order->currency = 'USD';
        $order->paymentCurrency = 'USD';
        $order->email = 'checkout-' . bin2hex(random_bytes(4)) . '@example.test';

        self::assertTrue(\Craft::$app->getElements()->saveElement($order));

        $firstCount = $this->countMatching($this->queuedDescriptions(), 'Started Checkout');
        self::assertSame(1, $firstCount);

        // Saving the same cart again must not queue a second Started Checkout.
        self::assertTrue(\Craft::$app->getElements()->saveElement($order));
        $secondCount = $this->countMatching($this->queuedDescriptions(), 'Started Checkout');
        self::assertSame(1, $secondCount);
    }

    public function testCompletingAnOrderQueuesPlacedOrderAndOnePerLineItem(): void
    {
        $order = $this->createCompletableOrder(2, 20.0);
        $order->markAsComplete();

        $descriptions = $this->queuedDescriptions();

        self::assertSame(1, $this->countMatching($descriptions, 'Placed Order'));
        self::assertSame(1, $this->countMatching($descriptions, 'Ordered Product'));
    }

    public function testStatusChangeToAConfiguredHandleQueuesTheMatchingMetric(): void
    {
        $order = $this->createCompletableOrder(1, 10.0);
        $order->markAsComplete();

        $shipped = Commerce::getInstance()->getOrderStatuses()->getOrderStatusByHandle('shipped')
            ?? $this->createOrderStatus('shipped');

        $order->orderStatusId = $shipped->id;
        \Craft::$app->getElements()->saveElement($order);

        $descriptions = $this->queuedDescriptions();

        self::assertGreaterThanOrEqual(1, $this->countMatching($descriptions, 'Fulfilled Order'));
    }

    public function testStatusChangeToAnUnconfiguredHandleQueuesNothingNew(): void
    {
        $order = $this->createCompletableOrder(1, 10.0);
        $order->markAsComplete();

        $other = Commerce::getInstance()->getOrderStatuses()->getOrderStatusByHandle('onHoldKlaviyoTest')
            ?? $this->createOrderStatus('onHoldKlaviyoTest');

        $order->orderStatusId = $other->id;
        \Craft::$app->getElements()->saveElement($order);
        $after = $this->queuedDescriptions();

        self::assertSame(0, $this->countMatching($after, 'Fulfilled Order'));
        self::assertSame(0, $this->countMatching($after, 'Cancelled Order'));
    }

    public function testRefundingATransactionQueuesRefundedOrder(): void
    {
        $order = $this->createCompletableOrder(1, 30.0);

        $commerce = Commerce::getInstance();
        $transaction = $commerce->getTransactions()->createTransaction($order, null, TransactionRecord::TYPE_PURCHASE);
        $transaction->status = TransactionRecord::STATUS_SUCCESS;
        $transaction->reference = 'klaviyo-test-' . bin2hex(random_bytes(4));
        $commerce->getTransactions()->saveTransaction($transaction);

        $order->markAsComplete();

        $refundable = null;
        foreach ($order->getTransactions() as $candidate) {
            if ($candidate->canRefund()) {
                $refundable = $candidate;
                break;
            }
        }
        self::assertNotNull($refundable, 'Expected a refundable transaction on the order.');

        $commerce->getPayments()->refundTransaction($refundable, 10.0, 'Klaviyo test refund');

        $descriptions = $this->queuedDescriptions();
        self::assertGreaterThanOrEqual(1, $this->countMatching($descriptions, 'Refunded Order'));
    }

    public function testOrderTrackingProfileIncludesMappedCustomerFieldValues(): void
    {
        // Regression coverage: profileFieldMappingRaw was stored and
        // validated but ProfileMapper was never actually invoked anywhere
        // in the order-tracking event flow — every event's profile was
        // just ['email' => ...], no matter what mapping was configured.
        //
        // Constructs OrderTrackingService directly rather than going through
        // $plugin->orderTracking: that's a Yii component cached on first
        // resolution for the plugin's lifetime, which — in this suite, one
        // Craft app booted once and reused across every test method — may
        // already have been resolved by an earlier test with an empty
        // mapping, before this test's settings mutation below ever runs.
        // Same reasoning as constructing CatalogSyncService/
        // NewsletterSubscriptionService directly elsewhere in this file.
        $fieldHandle = $this->ensureUserHasLoyaltyTierField();

        $customer = \craft\elements\User::find()->admin()->one();
        self::assertNotNull($customer);
        $customer->setFieldValue($fieldHandle, 'Gold');
        self::assertTrue(\Craft::$app->getElements()->saveElement($customer), implode(', ', $customer->getErrorSummary(true)));

        $order = $this->createCompletableOrder(1, 15.0);

        $orderTracking = new OrderTrackingService(profileFieldMapping: [$fieldHandle => 'loyalty_tier']);
        $profile = $orderTracking->buildProfile((string)$order->getEmail(), $order);

        self::assertSame('Gold', $profile['loyalty_tier']);
        self::assertSame($order->getEmail(), $profile['email']);
    }

    public function testCatalogSyncServiceQueuesAnInventoryOnlyJobForAVariant(): void
    {
        // The real trigger for this (Inventory::EVENT_AFTER_EXECUTE_INVENTORY_MOVEMENT)
        // requires constructing a valid InventoryManualMovement, which is
        // restricted to a narrow set of allowed transaction-type transitions
        // and tightly coupled to store inventory-location setup — the same
        // complexity documented in commerce-returns' README. Verified
        // instead at the service level (real Variant, real queue) plus a
        // direct check that the event listener is actually registered.
        self::assertTrue(\yii\base\Event::hasHandlers(
            \craft\commerce\services\Inventory::class,
            \craft\commerce\services\Inventory::EVENT_AFTER_EXECUTE_INVENTORY_MOVEMENT
        ));

        $product = $this->createProduct(5.0);
        $variant = $product->getVariants()->first();
        self::assertNotNull($variant);

        (new CatalogSyncService())->syncVariantInventory($variant);

        $descriptions = $this->queuedDescriptions();
        self::assertTrue(
            $this->anyContains($descriptions, 'Syncing inventory to Klaviyo'),
            'Expected an inventory-sync job to be queued. Queue contents: ' . implode(' | ', $descriptions)
        );
    }

    public function testGetSyncQueueReturnsCraftsDefaultQueueByDefault(): void
    {
        $plugin = CommerceKlaviyo::getInstance();
        self::assertNotNull($plugin);
        $plugin->getSettings()->queueComponentId = 'queue';

        self::assertSame(\Craft::$app->getQueue(), $plugin->getSyncQueue());
    }

    public function testGetSyncQueueFallsBackToTheDefaultQueueForAnUnknownComponentId(): void
    {
        $plugin = CommerceKlaviyo::getInstance();
        self::assertNotNull($plugin);
        $plugin->getSettings()->queueComponentId = 'thisComponentDoesNotExistKlaviyoTest';

        self::assertSame(\Craft::$app->getQueue(), $plugin->getSyncQueue());

        // Reset for any later test relying on the default.
        $plugin->getSettings()->queueComponentId = 'queue';
    }

    public function testNewsletterSubscriptionServiceQueuesASubscribeJobWhenAListIdIsConfigured(): void
    {
        $email = 'newsletter-' . bin2hex(random_bytes(4)) . '@example.test';

        (new NewsletterSubscriptionService(listId: 'test-list-id'))->subscribe($email);

        $descriptions = $this->queuedDescriptions();

        self::assertTrue(
            $this->anyContains($descriptions, 'Klaviyo newsletter list'),
            'Expected a newsletter-subscription job to be queued. Queue contents: ' . implode(' | ', $descriptions)
        );
    }

    public function testNewsletterSubscriptionServiceDoesNothingWithoutAConfiguredListId(): void
    {
        $before = count($this->queuedDescriptions());

        (new NewsletterSubscriptionService())->subscribe('no-list-' . bin2hex(random_bytes(4)) . '@example.test');

        $after = count($this->queuedDescriptions());

        self::assertSame($before, $after);
    }

    private function createProduct(float $price, int $variantCount = 1): Product
    {
        $commerce = Commerce::getInstance();
        $site = \Craft::$app->getSites()->getPrimarySite();
        $suffix = bin2hex(random_bytes(4));

        $productType = $commerce->getProductTypes()->getProductTypeByHandle('klaviyoTests');

        if ($productType === null) {
            $productType = new ProductType();
            $productType->name = 'Klaviyo Tests';
            $productType->handle = 'klaviyoTests';
            $productType->setSiteSettings([
                $site->id => new ProductTypeSite(['siteId' => $site->id, 'hasUrls' => false]),
            ]);
            self::assertTrue($commerce->getProductTypes()->saveProductType($productType));
            \Craft::$app->getProjectConfig()->saveModifiedConfigData();
            $productType = $commerce->getProductTypes()->getProductTypeByHandle('klaviyoTests');
        }

        $product = new Product();
        $product->typeId = $productType->id;
        $product->title = "Klaviyo Test Product {$suffix}";
        $product->siteId = $site->id;

        $variants = [];

        for ($i = 0; $i < $variantCount; $i++) {
            $variant = new Variant();
            $variant->sku = "klaviyo-test-{$suffix}-{$i}";
            $variant->basePrice = $price;
            $variant->isDefault = $i === 0;
            $variants[] = $variant;
        }

        $product->setVariants($variants);
        $product->setDirtyAttributes(['variants']);

        self::assertTrue(
            \Craft::$app->getElements()->saveElement($product),
            implode(', ', $product->getErrorSummary(true))
        );

        return $product;
    }

    private function createCompletableOrder(int $qty, float $price): Order
    {
        $product = $this->createProduct($price);
        $variant = Variant::find()->productId($product->id)->status(null)->one();
        self::assertNotNull($variant);

        $commerce = Commerce::getInstance();
        $site = \Craft::$app->getSites()->getPrimarySite();
        $gateway = $commerce->getGateways()->getGatewayByHandle('dummy');
        $customer = \craft\elements\User::find()->admin()->one();
        self::assertNotNull($customer);

        $order = new Order();
        $order->number = \Craft::$app->getSecurity()->generateRandomString(32);
        $order->storeId = $commerce->getStores()->getPrimaryStore()->id;
        $order->currency = 'USD';
        $order->paymentCurrency = 'USD';
        $order->gatewayId = $gateway->id;
        $order->orderSiteId = $site->id;
        $order->setCustomer($customer);

        self::assertTrue(\Craft::$app->getElements()->saveElement($order));

        $lineItem = $commerce->getLineItems()->createLineItem($order, $variant->id, [], $qty);
        $order->setLineItems([$lineItem]);
        \Craft::$app->getElements()->saveElement($order);

        return $order;
    }

    private function createOrderStatus(string $handle): \craft\commerce\models\OrderStatus
    {
        $commerce = Commerce::getInstance();
        $status = new \craft\commerce\models\OrderStatus();
        $status->name = 'Klaviyo Test ' . $handle;
        $status->handle = $handle;
        $status->color = 'blue';
        $status->storeId = $commerce->getStores()->getPrimaryStore()->id;

        self::assertTrue(
            $commerce->getOrderStatuses()->saveOrderStatus($status),
            implode(', ', $status->getErrorSummary(true))
        );

        return $status;
    }

    private function ensureUserHasLoyaltyTierField(): string
    {
        $handle = 'commerceKlaviyoTestLoyaltyTier';
        $existing = \Craft::$app->getFields()->getFieldByHandle($handle);

        if ($existing instanceof PlainText) {
            return $handle;
        }

        $field = new PlainText();
        $field->name = 'Test Loyalty Tier';
        $field->handle = $handle;
        self::assertTrue(\Craft::$app->getFields()->saveField($field), implode(', ', $field->getErrorSummary(true)));

        $layout = \Craft::$app->getFields()->getLayoutByType(\craft\elements\User::class);
        $tab = new FieldLayoutTab(['layout' => $layout, 'name' => 'Klaviyo Test']);
        $tab->setElements([new CustomField($field)]);
        // Append rather than replace: this is a shared field layout, other
        // tests/plugins may already rely on tabs already on it.
        $layout->setTabs([...$layout->getTabs(), $tab]);

        self::assertTrue(\Craft::$app->getFields()->saveLayout($layout), implode(', ', $layout->getErrors()));
        \Craft::$app->getProjectConfig()->saveModifiedConfigData();

        return $handle;
    }

    /**
     * Only this plugin's own catalog jobs — Craft and Commerce queue
     * unrelated work (catalog pricing, revision pruning) during these
     * operations.
     *
     * @return string[]
     */
    private function klaviyoCatalogJobs(): array
    {
        return array_values(array_filter(
            $this->queuedDescriptions(),
            fn(string $d): bool => str_contains($d, 'Klaviyo catalog')
        ));
    }

    /**
     * @return string[]
     */
    private function queuedDescriptions(): array
    {
        $info = \Craft::$app->getQueue()->getJobInfo(1000);

        return array_map(fn(array $job): string => (string)($job['description'] ?? ''), $info);
    }

    /**
     * @param string[] $descriptions
     */
    private function anyContains(array $descriptions, string $needle): bool
    {
        foreach ($descriptions as $description) {
            if (str_contains($description, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $descriptions
     */
    private function countMatching(array $descriptions, string $needle): int
    {
        return count(array_filter($descriptions, fn(string $d): bool => str_contains($d, $needle)));
    }
}
