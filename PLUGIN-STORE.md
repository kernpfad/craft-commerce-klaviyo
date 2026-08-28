# Craft Plugin Store copy — Commerce Klaviyo

Paste into the [Craft Plugin Store](https://plugins.craftcms.com) developer portal. English is the store default.

**Documentation URL:** https://kernpfad.dev/en/craft/plugins/craft-commerce-klaviyo/docs/

## Short description

Live Klaviyo catalog sync, reserved Commerce metrics, back-in-stock, and queued API calls — so flows and inventory stay accurate without risking checkout.

## Long description

**Commerce Klaviyo** connects Craft Commerce to Klaviyo with a live catalog, reserved ecommerce metrics, and queue-isolated API calls — so marketing automation never blocks checkout.

### Live product catalog

Products and variants sync to Klaviyo’s Catalog API as you save them: titles, prices, SKUs, URLs, images, categories, and inventory. Stock changes push lightweight inventory updates that power Klaviyo’s back-in-stock detection. Deletes and unpublishes stay in sync so discontinued items disappear from product blocks and signup flows. A full **reindex** command (and CP “Sync now”) rebuilds the catalog via bulk jobs after install or recovery.

### Ecommerce events that match Klaviyo’s flows

Server-side tracking uses Klaviyo’s own reserved metric names, so pre-built flows work without remapping triggers:

- **Started Checkout** / **Updated Cart** (with `CheckoutURL` and `OrderId` for abandon flows)
- **Placed Order** / **Ordered Product**
- **Fulfilled Order** / **Cancelled Order** (mapped to your Commerce statuses)
- **Refunded Order**

Optional onsite JS adds **Viewed Product** and **Added to Cart**. A cart **restore** action lets email links reopen incomplete carts.

### Back in stock & lists

Ship a Twig back-in-stock form with a server-side stock guard, clearer API error messages, optional list subscribe after signup, and an inventory reporting threshold to keep high-stock noise out of Klaviyo. Newsletter signup works via a built-in action or an optional Formie binding — always DOI-aware list subscribe, always queued.

### Forms, fields & backfill

Optional public **identify / track** actions for Twig forms (custom events, list subscribe, optional order tracking — off by default). **Klaviyo List** / **Lists** field types for entries and users. **Historical order sync** from the console or Utilities so past orders land in Klaviyo with their original timestamps.

### Built for production Craft shops

- Every outbound call runs on the queue (dedicated queue component supported)
- Env-based API keys for project config
- Consent webhook → local opt-out field
- Agency hooks to reshape payloads before they leave Craft
- Connection test and CP status for catalog health

**Requires** Craft CMS 5, Craft Commerce 5, PHP 8.2+, and a Klaviyo private API key with catalog, events, profiles, lists, and back-in-stock scopes.
