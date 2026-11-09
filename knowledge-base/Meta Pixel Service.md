# Meta Pixel Service

Repo: `Osmium-Services/osmium-meta-pixel`, service id `meta-pixel`, category marketing-analytics. Currently v1.2.6<!--v:meta-pixel-->. v1.2.0 added shop events and the server-side Conversions API Purchase; v1.2.1 stops contacting Meta before consent.

## What it does

- Admin page `admin/settings/meta-pixel`: enable toggle and Pixel ID (must be 15-16 digits; rejected otherwise so
  a typo or pasted snippet can't reach the inline script).
- A `head` theme block that loads `fbevents.js` and sends a PageView, **only after the visitor has accepted cookies** (see
  Consent). **Production only** (never on `dev` or `staging`).
- Optional shop events and a server-side Purchase, both off by default (see Shop events and Conversions API).
- Settings are stored in `app/config/services/meta-pixel.json.php`. Disabled by default. The admin (this page included) was
  unreachable in testing while `environment` was `live` (it redirected home), so change settings in `dev` first.

## Requires Cookie Consent

Since v1.1.0 the manifest lists `requires.services: ["cookie-consent"]`, so installing the pixel installs Cookie Consent
first (it is listed as enabled, but its own settings start switched off: tick Enabled on its settings page and save, or nothing is tracked). The pixel still never reads Cookie Consent's PHP. It reads
the consent cookie (or the name in `window.OsmiumCookieConsent.cookieName`) and listens for `osmium:consent`. The old
"needs a consent tool" banner on the settings page was removed in v1.1.2, because the requirement is now enforced at install.
A previous version had no dependency, which was the loose-coupling choice; see [[Lessons Learned]].

## CSP

Core sends a Content-Security-Policy that only allows Google's sources. The manifest therefore declares
`csp.script-src: https://connect.facebook.net` and `csp.connect-src: https://www.facebook.com https://connect.facebook.net`
(`img-src` already allows `https:`). v1.0.0 to v1.0.2 omitted this, so the browser blocked `fbevents.js` in
production. Fixed in v1.0.3. Any new service that loads third-party scripts must declare its sources the same way.

## Consent

- **No contact with Meta before Accept (v1.2.1).** `fbevents.js` is not fetched, and there is no `preconnect` hint, until the
  cookie reads `accepted` (a returning visitor, loaded when idle) or the visitor clicks Accept (`osmium:consent`, loaded at once).
  Before that `fbq` only queues calls in memory, so Meta gets no request and no IP address. Browser-tested with the real banner.
- **Re-grant after load.** `fbevents.js` works through the queued calls up to the first `revoke` and then holds everything after
  it, including a `grant` queued before it loaded. The block therefore calls `fbq('consent','grant')` again in the script's
  `onload` when consent has been given. Without that, a first-time Accept loaded the script but sent nothing.
- Legal points still open (not code, not legal advice): the default banner text says "measure site usage" and does not mention
  advertising or Meta; the privacy notice must name Meta; consent for the server Purchase is captured once, at checkout, so a
  withdrawal before a delayed payment webhook is not honoured.
- With Cookie Consent's own Enabled switch off there is no banner and no cookie, so nothing is tracked or sent (tested 2026-10-06).

Older behaviour that still holds:

- The pixel initialises with `fbq('consent','revoke')`, so Meta sets no cookies and sends nothing.
- It calls `fbq('consent','grant')` only if the cookie named in `window.OsmiumCookieConsent.cookieName`
  (falling back to `osmium_cookie_consent`) reads `accepted`.
- For a returning visitor whose cookie already reads `accepted`, it grants **before** `init` instead of revoking first (v1.1.3).
  Revoke, init, PageView, then grant in the same tick sent no tracking request in Chrome, so a returning accepted visitor
  was never tracked. Live Accept (a later `osmium:consent` event) was unaffected.
- With no consent service present it stays revoked, but the service can no longer be installed without one.
- The head block listens for the `osmium:consent` event that Cookie Consent's `site-modal.js` fires when a visitor clicks Accept or
  Reject, and calls `fbq('consent', grant|revoke)` itself, so the change applies on the same page. Core no longer calls `fbq` (v1.0.4).
- There is deliberately **no `<noscript>` pixel image**: it would fire with no consent possible.

## Convention notes

- The head block reads its config from the `$metaPixel` variable the loader injects
  (`ServiceLoader::serviceConfigContext()`), not by calling `MetaPixelConfig::get()`. If a class can't be
  autoloaded the block renders nothing instead of crashing the page. v1.0.1 did the latter; see
  [[Incident - Stale Autoload Cache]].
- Block order is `ORDER BY name ASC`, which happens to render Cookie Consent before Meta Pixel. The pixel doesn't
  depend on that order.

## Shop events and Conversions API (v1.2.0, tested 2026-10-06)

Off by default; two switches on the settings page, plus an access token (password field, blank keeps the saved one,
never sent back to the page) and an optional test event code.

- **Browser events** (`shopEvents`): ViewContent, AddToCart, Purchase. The base ships no product or thank-you views, so
  core exposes a vendor-neutral contract (THEME-CONTRACT.md section 7): `/assets/services/osmium-shop/shop-events.js` turns JSON tags into
  an `osmium:shop-event` on `document` (types `view_item`, `purchase`); `osmium:basket-added` already existed for add to cart.
  The head block maps them to `fbq('track', ...)`. A late listener replays `window.osmiumShopEvents`. `$purchase` on the
  thank-you page is set once from the session after the visitor's own payment (`purchase_event` in `checkout-action.php`).
- **Server Purchase** (`serverEvents`): core fires `order.created` and `order.paid` from `ShopOrderService`
  (payload `orderId`, `order` with items, `dataSource`). `MetaServerEvents::orderCreated` runs in the visitor's request and
  stores consent (the Cookie Consent cookie, name read via core's config context), `_fbp`, `_fbc` and the referer in
  `meta_pixel_order_context` (service migration). `orderPaid` (browser capture or provider webhook) sends one Purchase to
  `graph.facebook.com/v25.0/<pixel>/events` only if consent was recorded, and stores the result on that row.
- **Dedup:** browser `eventID` and server `event_id` are both the order ref.
- Production only, like the pixel.

Server events cover Purchase only, not ViewContent or AddToCart. There is no retry if Meta is down: the failure is stored on
the context row. If an order has no context row (created before the service was installed or enabled) nothing is sent.

**Graph API version.** `MetaServerEvents::GRAPH_VERSION` is `v25.0`. Checked 2026-10-06 on Meta's versions page: v26.0 is the
latest (released 2026-07-29) and v25.0 is supported until 2028-07-29. Moving up is a one-line change.

**Not verified:** Meta accepting an event (needs a real access token and a test event code from Events Manager); the order
flow in a real themed checkout, including `createPendingOrder` firing `order.created` (the test called that hook directly);
a real product page with `add-to-basket.js` and `shop-events.js`. The 22 skipped integration tests cover none of this.

## Browser-tested (2026-10-05, before v1.2.1)

Run against the base homepage with the environment temporarily set to `live` (both blocks are production-only),
Cookie Consent installed and enabled, and a fake pixel ID:

- Before consent: only `fbevents.js` is fetched (since v1.2.1 not even that). No `/tr` event requests, no Meta cookies.
- After Accept: `signals/config/<id>` and `facebook.com/tr/?id=<id>&ev=PageView` fire on the same page.
- After Reject, and on a reload: only `fbevents.js` is fetched, nothing else.
- Not tested: a real pixel ID against Meta, or running without Cookie Consent installed (by the code it stays revoked).

Retested 2026-10-05 on v1.1.3 with Analytics and Clarity installed too, counting Facebook requests: fresh visit 1, after Accept 3,
returning accepted visitor 3, after Reject no new requests, returning rejected visitor 1. All three services followed the
same Accept and Reject clicks.

## Tested 2026-10-06 (v1.2.0 and v1.2.1)

Method: install through the admin Store, set `environment` to `live`, fake pixel ID `123456789012345`, Cookie Consent enabled,
real banner clicked (not a synthetic event; the first run had Cookie Consent disabled and was redone). The shop events were fired
as synthetic `osmium:shop-event` and `osmium:basket-added` events on the base homepage, which has no product or thank-you page.
Change settings in `dev` first (the admin is unreachable on `live`).

- **Fresh visit, no cookie (v1.2.1):** zero requests to any Facebook host, even after scroll, keypress and 7 seconds idle; no
  `fbevents.js` script tag on the page.
- **Real Accept click:** `fbevents.js`, `signals/config` and `tr` requests for PageView, ViewContent, AddToCart (value 200 for 2
  units from a basket line of 3 at 300 inc. tax) and Purchase with `eid=SG2-1001`.
- **Real Reject click:** a later ViewContent produced no request.
- **Returning visitor who already accepted:** loads on idle and sends PageView.
- **Cookie Consent switched off:** nothing sent, browser or server.
- **Server Purchase** (two test orders through the real `markPaid` and the `order.paid` hook, fake token): the consented order stored
  consent, `_fbp`, `_fbc` and the referer, and the send reached Meta, which answered HTTP 400 "Invalid OAuth access token"; the
  rejected order and an order with no cookies stored consent 0 and sent nothing; a second `markPaid` returned null and did not
  resend. The hashing and event body were checked separately (email and postcode SHA-256 match by hand).
- **Admin page:** the saved token shows the placeholder "Saved - leave blank to keep" and appears nowhere in the page HTML.
- **Bug found by this test:** a first-time Accept loaded `fbevents.js` but sent nothing (see Consent, re-grant). Fixed in v1.2.1.
- **Bug found in core by this test:** the genesis migration never inserted the `invoice_counter` row (fixed in core, 251c3ef).
