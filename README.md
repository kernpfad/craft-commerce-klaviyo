# Commerce Klaviyo

A Klaviyo integration for Craft Commerce: real-time product catalog sync (with back-in-stock support), standard ecommerce event tracking that matches Klaviyo's own metric names, and custom profile field mapping — every API call is queued and isolated so a Klaviyo outage never blocks checkout.

See [CHANGELOG.md](CHANGELOG.md) for release notes, [CONTRIBUTING.md](CONTRIBUTING.md) for local QA commands, and [SECURITY.md](SECURITY.md) for vulnerability reporting.

## Requirements

- Craft CMS 5.0.0+
- Craft Commerce 5.0.0+
- PHP 8.2+
- A Klaviyo account and a private API key with the `events:write`, `profiles:write`, `catalogs:write`, `catalogs:read`, `lists:read`, and `back-in-stock-subscriptions:write` scopes

## Installation

```sh
composer require kernpfad/craft-commerce-klaviyo
php craft plugin/install commerce-klaviyo
```

## Historical order sync

After install (or when connecting a new Klaviyo account), backfill completed orders:

```sh
php craft commerce-klaviyo/sync-orders --from=2024-01-01 --to=2024-12-31
php craft queue/run
```

Or use **Utilities → Klaviyo historical orders** in the control panel. Events use each order’s original `dateOrdered` timestamp; Klaviyo dedupes by `unique_id` (order number / line id).

## Events

The plugin uses Klaviyo's own reserved metric names, so Klaviyo's pre-built flow templates work without rebuilding their triggers:

| Metric | Fired when |
|---|---|
| `Started Checkout` | An incomplete cart gets an email address for the first time. Tracked once per cart. |
| `Placed Order` | An order is completed. |
| `Ordered Product` | Once per line item on completion. |
| `Fulfilled Order` / `Cancelled Order` | An order reaches a status you designate in settings. Commerce statuses are store-defined, so there is no universal handle to assume. |
| `Refunded Order` | On every refund transaction. |

## Catalog sync

- Saving a product pushes it as a catalog item; saving a variant pushes it as a catalog variant with title, price, SKU, URL, inventory quantity, and images (`image_full_url`, `image_thumbnail_url`, `images[]` from the configured Assets field — variant first, then product).
- Every inventory movement — a sale, a restock, a manual adjustment — pushes a lightweight inventory-only update, which is what Klaviyo's back-in-stock detection reads.
- Deleting a product removes its catalog item and Klaviyo cascades the variants. Removing a single variant removes just that catalog variant, so a discontinued size stops appearing in product blocks and stops accepting back-in-stock signups.

## Back-in-stock subscriptions

A product page can POST to `commerce-klaviyo/subscriptions/back-in-stock` so customers can be notified when an out-of-stock variant returns. Email channel only.

This endpoint is deliberately synchronous, so the customer gets an immediate confirmation. It is bounded by an explicit request timeout rather than by the queue.

Under **Settings → Plugins → Commerce Klaviyo → Back in stock** you get a copyable Twig form snippet for standard (non-headless) product templates, plus an optional **inventory reporting threshold**: when set to N, tracked variants only send their real stock to Klaviyo once quantity is at or below N (above N, Klaviyo sees a high placeholder). That keeps low-inventory / back-in-stock flows from reacting to high-stock noise. There is no automatic storefront injection — paste the snippet into your theme.

The public action also enforces a **stock guard** server-side: signups are rejected when the variant is in stock or does not track inventory (even if someone POSTs without your theme form). Klaviyo error responses are mapped to clearer messages (e.g. already subscribed). Optionally, enable **also subscribe to a Klaviyo list** to queue a marketing list signup after a successful back-in-stock request.

## Newsletter signup

Off by default. Enable `newsletterSignupEnabled` and pick a Klaviyo list in settings, then use either path:

- **This plugin's own action**, `commerce-klaviyo/newsletter/subscribe`. No other plugin required.
- **A bound [Formie](https://formie.verbb.io) form**, if Formie is installed. Pick the form and which of its fields hold email and names; every successful submission subscribes automatically, reusing this plugin's API key. Formie is never a hard dependency and this feature has no effect without it.

Both paths queue the subscription like every other call.

## Settings

Under **Settings → Plugins → Commerce Klaviyo**:

| Setting | Default | Description |
|---|---|---|
| `apiKey` | `null` | Klaviyo private API key. Accepts an env var reference (e.g. `$KLAVIYO_API_KEY`) so the real key never has to be committed to project config. |
| `queueComponentId` | `queue` | Yii application component the sync jobs run on. Set this to a dedicated [yii2-queue](https://www.yiiframework.com/extension/yiisoft/yii2-queue) component to isolate Klaviyo traffic from the rest of the site's jobs. Falls back to the default queue, logged rather than thrown, if unavailable. |
| `fulfilledStatusHandles` | none | Which of your order statuses mean "fulfilled". |
| `cancelledStatusHandles` | none | Which mean "cancelled". |
| `descriptionFieldHandle` | `null` | Custom field handle for the Klaviyo catalog description. Falls back to the product title. |
| `imageFieldHandle` | `null` | Assets field for catalog images (variant first, then product). Sets `image_full_url`, `image_thumbnail_url`, and `images[]`. Omitted when empty. |
| `categoriesFieldHandle` | `null` | Categories field whose selected categories sync as Klaviyo catalog categories. |
| `inventoryReportingThreshold` | `null` | Optional. When set to N, tracked variants only report real stock to Klaviyo at or below N; above N they report a high placeholder. Leave empty to always send real stock. |
| `profileFieldMappingRaw` | empty | Profile field mapping, one `handle=klaviyoProperty` per line. |
| `newsletterSignupEnabled` | `false` | Master switch for the newsletter signup. |
| `newsletterListId` | `null` | Target Klaviyo list (selected from a live list picker). |
| `newsletterFormieFormId` | `null` | Formie form to bind, if using that path. |
| `newsletterFormieEmailFieldHandle` | `email` | Formie field holding the email address. |
| `newsletterFormieFirstNameFieldHandle` | `null` | Optional. |
| `newsletterFormieLastNameFieldHandle` | `null` | Optional. |
| `webhookEnabled` | `false` | Accept signed Klaviyo consent webhooks. |
| `webhookSecret` | `null` | Webhook signing secret (env-var capable). |
| `optOutFieldHandle` | `null` | User field handle updated on unsubscribe/resubscribe. |
| `onsiteTrackingEnabled` | `false` | Load Klaviyo's public JavaScript snippet on the storefront for browse/cart events. |
| `publicApiKey` | `null` | Klaviyo public API key (six-character site ID). Env-var capable. Never the private key. |

Profile field mapping applies in three places: every order-tracking event's profile is enriched from the order's associated user (or from its billing/shipping address on guest checkout), the Formie-bound signup maps the submitted form's own values the same way, and catalog description/image come from the configured product field handles.

## Payload customization (agencies)

Register listeners on `CommerceKlaviyo` to adjust Klaviyo payloads before they are queued or sent:

| Event constant | Fired before |
|---|---|
| `EVENT_BEFORE_BUILD_CATALOG_ITEM_PAYLOAD` | A catalog item sync job is queued |
| `EVENT_BEFORE_BUILD_CATALOG_VARIANT_PAYLOAD` | A catalog variant sync job is queued |
| `EVENT_BEFORE_BUILD_CATALOG_INVENTORY_PAYLOAD` | An inventory-only catalog PATCH |
| `EVENT_BEFORE_BUILD_BACK_IN_STOCK_PAYLOAD` | A back-in-stock subscription POST |
| `EVENT_BEFORE_BUILD_TRACK_EVENT_PAYLOAD` | An ecommerce metric event job is queued |
| `EVENT_BEFORE_BUILD_NEWSLETTER_PAYLOAD` | A newsletter subscription job runs |

Each event carries a mutable `payload` array plus typed context (product, variant, order, etc.).

## Inbound consent webhooks

When `webhookEnabled` is on, POST signed Klaviyo system-webhook payloads to `actions/commerce-klaviyo/webhook/receive`. Configure Klaviyo topics such as `event:klaviyo.unsubscribed_from_email_marketing` and map them to a Lightswitch user field via `optOutFieldHandle`. Matching Craft users are updated when they unsubscribe or re-subscribe in Klaviyo.

## Testing the connection

```sh
php craft commerce-klaviyo/test
```

Or click **Test connection** on the settings screen. Both make a single lightweight `GET /api/accounts/` call to verify the configured API key works, with no side effects on the Klaviyo account.

## Reindexing the catalog

```sh
php craft commerce-klaviyo/reindex
```

Re-queues every published product and its variants for a full catalog resync using Klaviyo's bulk catalog jobs (up to 100 resources per API call) — useful after first install, or to recover from Klaviyo-side data loss. Item bulk jobs are queued before variant bulk jobs; each variant chunk still ensures its parent catalog items exist before calling Klaviyo. Real-time saves on individual products/variants continue to use per-element queue jobs. A mutex guards against two reindex runs enqueueing the same catalog concurrently. This only queues the jobs — run `php craft queue/run` (or let a worker pick them up) to actually push them to Klaviyo.

## Reliability

Every call on the order and catalog paths runs as a queue job, never inline in a customer-facing request. A failed job is logged and retried by Craft's queue like any other; it cannot surface as an error during checkout. The back-in-stock endpoint is the one deliberate exception, as described above.

## Limitations

- **No SMS or push consent management.**
- **Optional onsite tracking** (`onsiteTrackingEnabled`, off by default): loads Klaviyo's public JavaScript snippet on the storefront and tracks `Viewed Product` on standard Commerce product templates plus `Added to Cart` on cart updates. Uses variant IDs that match catalog sync and server-side order events. Headless front ends should leave this off and track in their own JavaScript.
- **`Started Checkout` is inferred, not literal.** Commerce has no distinct "customer clicked checkout" event for a custom or headless front end, so the metric fires when the customer becomes identifiable rather than at the exact click. It includes a `CheckoutURL` when Commerce can generate a load-cart URL for the cart.

## License

Licensed under the [Craft License Agreement](LICENSE.md).

[Legal notice](https://kernpfad.dev/en/legal-notice) · [Privacy policy](https://kernpfad.dev/en/privacy-policy) · [Terms and conditions](https://kernpfad.dev/en/terms-and-conditions)
