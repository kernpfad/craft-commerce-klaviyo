# Commerce Klaviyo — Roadmap & Agent-Prompt

**Package:** `kernpfad/craft-commerce-klaviyo`  
**Handle:** `commerce-klaviyo`  
**Repo:** typisch `/agent/craft-commerce-klaviyo` oder GitHub `kernpfad/craft-commerce-klaviyo`

## Ist-Stand (kurz)

- Realtime Catalog Sync (Item/Variant), Inventory → Back-in-Stock
- Reserved Metrics: Started Checkout, Placed Order, Ordered Product, Fulfilled/Cancelled, Refunded
- Queue-first; Back-in-Stock-Endpoint absichtlich synchron
- Optional Newsletter (built-in Action + Formie soft-dep), List-Picker, Inbound Consent Webhooks
- Profile Field Mapping als Freitext `handle=key`
- Limits in README: kein SMS; Started Checkout inferiert; Onsite-JS optional im Plugin (KL-12)

## Bekannte technische Stolpersteine

- Variant-Upsert kann `400` liefern, wenn Parent-Catalog-Item noch fehlt → Job muss Parent vorher sicherstellen
- Ohne Full-Reindex fehlen bestehende Produkte nach Neuinstallation
- Private API Key landet in Plugin Settings / Project Config

---

## Backlog

### P0

| ID | Klasse | Item | Status |
|---|---|---|---|
| KL-01 | D | Console: `commerce-klaviyo/reindex` (Chunking, Lock, Progress) | ✅ erledigt — Mutex-Lock, Progress alle 50 Produkte, wiederverwendet `syncProduct()`/`syncVariant()` |
| KL-02 | C | Variant-Job: Parent-Item upserten bevor Variant (oder 404→ensure parent) | ✅ war bereits umgesetzt — Sync läuft auf `Variant::EVENT_AFTER_SAVE`, nicht `Product::EVENT_AFTER_SAVE` (siehe CHANGELOG) |
| KL-03 | D | API Key via Env (`$KLAVIYO_API_KEY`); Settings akzeptieren Alias | ✅ erledigt — `Settings::getApiKey()` via `App::parseEnv()`; vorher: Feld sah wie Env-Support aus, wurde aber nie aufgelöst |
| KL-04 | D | Connection-Test: CP-Button + `commerce-klaviyo/test` → `GET /api/accounts/` | ✅ erledigt — `KlaviyoClient::get()` (neu, gab es vorher gar nicht), CP-Button + Console-Command |

KL-02 war bereits gelöst; KL-01/03/04 waren echte Lücken (kein Console-Command, kein `GET`, `apiKey` wurde nie durch `parseEnv()` aufgelöst) und sind jetzt implementiert + getestet (Unit-Tests für `KlaviyoClient::get()` und `Settings::getApiKey()`, Console-Commands live gegen die Testsite smoke-getestet: Config-Check, Env-Auflösung, 166 Produkte + 166 Varianten korrekt eingereiht).

### P1

| ID | Klasse | Item | Status |
|---|---|---|---|
| KL-05 | B | Settings: `imageFieldHandle`, `descriptionFieldHandle` → Catalog Payload | ✅ erledigt — konfigurierbare Custom Fields, Fallback Titel / kein Bild |
| KL-06 | B | `Started Checkout`: `CheckoutURL` (absolute Cart/Checkout-URL) in Properties | ✅ erledigt — via `Order::getLoadCartUrl()` |
| KL-07 | B | Profile-Mapping auch aus Order-Address (Guest Checkout) | ✅ erledigt — Billing, Shipping als Fallback |
| KL-08 | D | CP: letzter Sync-/Track-Fehler + Timestamp | ✅ erledigt — Cache-basiert, Anzeige in Settings |

### P2

| ID | Klasse | Item | Status |
|---|---|---|---|
| KL-09 | D | Inbound Webhook: Unsubscribe / Consent → lokales Opt-out-Feld | ✅ erledigt — HMAC-verifizierter Endpoint + User-Feld |
| KL-10 | D | `EVENT_BEFORE_BUILD_*_PAYLOAD` für Agencies | ✅ erledigt — 6 Events auf `CommerceKlaviyo` |
| KL-11 | B | Newsletter: DOI-Status / List-Picker statt manueller List ID | ✅ erledigt — CP-List-Picker mit `opt_in_process` |
| KL-12 | A→Paket | Optionales Onsite-JS im Plugin (Viewed Product / Added to Cart) | ✅ erledigt — Toggle + Public Key, AssetBundle, Cart-AJAX + Session-Fallback |

### Foster-Parität (Abandoned Cart / Forms)

| ID | Item | Branch | Status |
|---|---|---|---|
| KL-13 | `Updated Cart` (gedämpft) + `trackUpdatedCart` + Cart Restore Action | `feature/abandoned-cart` | ✅ erledigt |
| KL-14 | Twig identify/track/subscribe Actions | `feature/track-actions` | ✅ erledigt |
| KL-15 | Klaviyo List / Lists Field Types | `feature/list-fields` | 🚧 in Arbeit |
| KL-16 | Historical order backfill | `feature/historical-orders` | offen (Welle 2) |

### Nicht tun

- Browse-Events server-seitig erzwingen
- Catalog/Order-Sync synchron im Web-Request
- Formie hard-requiren
- SMS-Consent halbgar einbauen

---

## Agent-Prompt (kopieren)

```markdown
Du arbeitest im Repo `kernpfad/craft-commerce-klaviyo` (Craft 5 / Commerce 5 Plugin).

## Ziel
Implementiere die P0-Items der Roadmap (KL-01 bis KL-04). P1 nur wenn P0 fertig und Zeit bleibt.

## Kontext
- Plugin-Handle: `commerce-klaviyo`
- Namespace: `kernpfad\commerceklaviyo`
- Klaviyo REST: `https://a.klaviyo.com/api/`, Revision laut `KlaviyoClient`
- Catalog: `$custom:::$default:::<externalId>`; External ID = Craft Element ID
- Sync läuft über Queue-Jobs; Back-in-Stock bleibt synchron
- README „Limitations“ und Settings-Twig sind Source of Truth für bestehende UX

## Anforderungen P0
1. **Reindex-Command** `php craft commerce-klaviyo/reindex`
   - Alle published Products + Variants in Chunks upserten
   - Parent-Item vor Variants
   - Lock gegen parallele Läufe
   - Fortschritt auf stdout; Fehler loggen, nicht still abbrechen
2. **Parent-before-variant** in Sync-Jobs / CatalogSyncService
   - Kein Variant-Create ohne existierendes Item
3. **Env-Secrets**
   - `apiKey` darf `$KLAVIYO_API_KEY` sein; `Craft::parseEnv` vor Client-Erzeugung
   - Settings-Instructions im Twig anpassen
4. **Connection-Test**
   - Console + optional CP-Button
   - Erfolgreich wenn Accounts-API 200; Scopes/Fehler verständlich ausgeben

## Qualitätsregeln
- `declare(strict_types=1);`, bestehende Patterns (Services, Jobs, PayloadBuilders)
- Unit-Tests für Payload/Ordering; Integration nur wenn Testsuite vorhanden
- PHPStan/ECS/Rector-kompatibel zum Repo
- CHANGELOG + README Limits aktualisieren (was gelöst vs. bewusst offen)
- Keine echten API-Keys committen

## Deliverable
- Branch `cursor/<kurzbeschreibung>-167c` falls Cloud-Agent
- Klare Commit-Messages
- Kurze Zusammenfassung: was gebaut, wie testen (`php craft commerce-klaviyo/test`, `reindex`)
```

## Manuelles Testen (craft-lab)

```sh
# .env
KLAVIYO_API_KEY=pk_…
KLAVIYO_LIST_ID=…

php craft plugin/install commerce-klaviyo
php craft commerce-klaviyo/test
php craft commerce-klaviyo/reindex
php craft queue/run
# In Klaviyo Admin: Catalog Items prüfen
```
