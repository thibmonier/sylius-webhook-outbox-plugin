# Sylius Webhook Outbox Plugin

[![Build](https://github.com/thibmonier/sylius-webhook-outbox-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-webhook-outbox-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Outgoing webhooks for Sylius 2 that are never lost: a transactional outbox, Standard Webhooks
signatures, retries, dead letters and replay from the admin panel.**

Your ERP, CRM or iPaaS wants to know when an order is placed, paid, shipped or cancelled, when
a customer signs up, when stock moves. Sylius 2 has no outgoing webhooks, and the usual
"dispatch an HTTP call in a listener" loses events when the call fails, sends events for
changes that were rolled back, and leaves no trace.

This plugin writes each event **in the same database transaction** as the change that caused
it (the outbox), then a worker delivers it with a signed HTTP request, retries for about three days,
parks what still fails in a dead-letter list and lets an administrator replay it. It is free
and open source.

## Features

| Need | What the plugin does |
|---|---|
| Never lose an event, never send a phantom one | Events are captured from the Doctrine unit of work and inserted **in the transaction of the business change**: committed if and only if the order, payment or customer is. Works whatever the entry point (shop, API, admin, CLI). |
| Know what happened in the shop | `order.placed`, `order.paid`, `order.cancelled`, `order.shipped`, `customer.created`, `customer.updated` (whitelisted fields only), `product_variant.stock_changed` (**one event per flush**, not per order line), `webhook.ping`. |
| Stable, documented payloads | A versioned envelope `{id, type, version, timestamp, channel, data}`; `data` comes from the plugin's own normalizers (no API Platform dump), never contains passwords, tokens or the order token. [JSON Schemas](docs/schemas) included. |
| Trust the sender | [Standard Webhooks](https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md) v1 signatures (`webhook-id`, `webhook-timestamp`, `webhook-signature: v1,<base64>`, `whsec_` secrets), tested against the vectors of the specification. Receiver snippets for PHP and Node below. |
| Survive receiver outages | Database-driven retries: immediately, 5 s, 5 min, 30 min, 2 h, 5 h, 10 h, 14 h, 20 h, 24 h (+ jitter). `Retry-After` honoured on 429/502/503/504 (all HTTP-date formats). `410 Gone` disables the endpoint and suspends its other deliveries. After the last attempt (about 3 days and 4 hours after the event) the delivery is **dead** (dead-letter list). |
| Operate it | **Integrations › Webhook endpoints / Webhook deliveries / Dead letters** in the admin: endpoint CRUD (secret shown **once**, regenerate, ping), deliveries grid with filters, delivery page (request body, truncated response, last 10 attempts), **Retry now**, **Replay** (single and bulk, same `webhook-id`). |
| Stay safe | Dedicated HTTP client: private and link-local networks blocked after DNS resolution (`NoPrivateNetworkHttpClient`), no redirects, https only, 15 s idle timeout, 30 s maximum duration. Secrets never logged nor shown again. |
| Run it anywhere | `tmonier:webhook:dispatch` works from cron (`--time-limit`) or as a long-running worker (`--loop`); claims use `SELECT … FOR UPDATE SKIP LOCKED` and a lease, so a crashed worker's delivery is picked up again. |
| Respect the GDPR | `tmonier:webhook:purge` (retention), `tmonier:webhook:anonymize-customer` (erasure of payloads **and** response excerpts). |
| Add your own events | Tagged event type registry and a public recorder API: an RFQ plugin can publish `quote.accepted`, an e-invoicing plugin `invoice.issued`. |

Also included: translations in English, French, German, Spanish, Italian, Dutch and Polish;
Twig Hooks; MySQL 8, MariaDB 10.6+ and PostgreSQL support.

## Screenshots

Screenshots go in `docs/images/`.

## Requirements

| | Version |
|---|---|
| PHP | 8.2+ |
| Sylius | 2.2, 2.3 |
| Symfony | 6.4, 7.4, 8.0 |
| Database | MySQL 8+, MariaDB 10.6+, PostgreSQL 10+ (see [Workers](#workers) for older servers) |

## Installation

1. Require the package:

    ```bash
    composer require tmonier/sylius-webhook-outbox-plugin
    ```

2. Register the bundle in `config/bundles.php` (Symfony Flex does it automatically once a recipe exists):

    ```php
    Tmonier\SyliusWebhookOutboxPlugin\TmonierSyliusWebhookOutboxPlugin::class => ['all' => true],
    ```

3. Import the configuration in `config/packages/tmonier_sylius_webhook_outbox.yaml`:

    ```yaml
    imports:
        - { resource: "@TmonierSyliusWebhookOutboxPlugin/config/config.yaml" }
    ```

4. Import the admin routes in `config/routes/tmonier_sylius_webhook_outbox.yaml`:

    ```yaml
    tmonier_sylius_webhook_outbox:
        resource: "@TmonierSyliusWebhookOutboxPlugin/config/routes.yaml"
    ```

5. Run the migrations:

    ```bash
    bin/console doctrine:migrations:migrate
    ```

6. Run the worker (see [Workers](#workers)) and schedule the purge, for instance daily:

    ```bash
    bin/console tmonier:webhook:purge
    ```

7. Set `framework.router.default_uri` (e.g. `https://shop.example.com`) so that the
   `admin_url` of payloads recorded from console commands point to your shop.

## Configuration

All keys are optional; these are the defaults.

```yaml
tmonier_sylius_webhook_outbox:
    enabled: true                    # capture events (the worker keeps delivering what is queued)
    events:                          # per type switch, e.g.
        product_variant.stock_changed: { enabled: true }
    retry_schedule: [0, 5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400]
                                     # seconds before attempt 1, 2, 3...; its length = attempts
    retry_jitter: 0.1                # up to +10% on each delay
    timeout: 15                      # idle timeout of a request, seconds
    max_duration: 30                 # total duration of a request, seconds
    response_body_max_bytes: 2048    # response excerpt kept for the admin panel
    retention_days: 30               # default of tmonier:webhook:purge (null: keep forever)
    allow_http_endpoints: false      # accept http:// URLs: development only
    allowed_private_networks: []     # e.g. ['10.0.12.0/24'] for an ERP on your LAN
    url_redaction_pattern: '…'       # path segments hidden in logs / errors / admin (null: none)
```

An event is stored only when at least one enabled endpoint subscribes to it (and accepts its
channel): without endpoints, the plugin writes nothing.

`allowed_private_networks` punches holes in the blocked ranges, everything else stays
blocked, including after DNS resolution. Use exact ranges.

## Events

| Type | When | `data` | Channel |
|---|---|---|---|
| `order.placed` | `checkoutState` becomes `completed` (or an order is created completed) | [order](docs/schemas/order.v1.json) | order channel |
| `order.paid` | `paymentState` becomes `paid` | order | order channel |
| `order.shipped` | `shippingState` becomes `shipped` | order | order channel |
| `order.cancelled` | `state` becomes `cancelled` | order | order channel |
| `customer.created` | a customer is created (guests included: Sylius creates one per checkout e-mail) | [customer](docs/schemas/customer.v1.json) | null |
| `customer.updated` | e-mail, first / last name, phone, gender, birthday, group or newsletter changes, or an existing customer (a former guest) creates an account (`has_account`); `changed_fields` lists them | customer | null |
| `product_variant.stock_changed` | `onHand` or `onHold` of variants changes, or a tracked variant is created (initial stock, `previous` null); **one event per flush** listing every variant | [stock](docs/schemas/product_variant.stock_changed.v1.json) | null |
| `webhook.ping` | **Ping** button; sent to that endpoint only | [ping](docs/schemas/webhook.ping.v1.json) | null |

Several events of one flush are independent: a receiver can get `order.paid` before
`order.placed` after a retry. `timestamp` has a one-second precision and is the same for the
events of one flush: order events by `id` instead (UUIDv7, time-ordered; the plugin makes the
ids of one process strictly increasing, so `order.placed` sorts before the `order.paid` of the
same flush), and rely on the state fields of `data`.

### Envelope

Every request body ([schema](docs/schemas/envelope.json)):

```json
{
    "id": "0199d3a8-5b1e-7c43-9a51-2f7f3c1d9e02",
    "type": "order.placed",
    "version": 1,
    "timestamp": "2026-10-11T08:14:03Z",
    "channel": "FASHION_WEB",
    "data": { "…": "…" }
}
```

- `id` is a UUIDv7, equal to the `webhook-id` header. It is **the same for every retry and
  replay** of the event: use it to deduplicate.
- `version` is the version of the `data` DTO of this type. Fields are only added within a
  version; a breaking change gets a new version.
- Dates are ISO 8601 (RFC 3339). Amounts are integers in the minor unit of the currency
  (cents). Ids are Sylius database ids.
- On MySQL the members of `data` may come in another order than documented (MySQL normalises
  JSON objects): parse JSON, never compare strings.

### `order.*` data (version 1)

```json
{
    "id": 20,
    "number": "000000020",
    "admin_url": "https://shop.example.com/admin/orders/20",
    "channel": "FASHION_WEB",
    "currency_code": "USD",
    "locale_code": "en_US",
    "state": "new",
    "checkout_state": "completed",
    "payment_state": "awaiting_payment",
    "shipping_state": "ready",
    "checkout_completed_at": "2026-10-04T00:28:14+00:00",
    "totals": { "items": 39105, "shipping": 595, "tax": 0, "promotion": -1000, "total": 39700 },
    "promotion_coupon": null,
    "customer": { "id": 13, "email": "aniyah@example.com", "first_name": "Aniyah", "last_name": "Larson", "phone_number": "1-520-372-9575", "has_account": true },
    "billing_address": { "first_name": "Eva", "last_name": "Batz", "company": null, "street": "8867 Zulauf Estate", "city": "Port Margretshire", "postcode": "62021", "country_code": "PL", "province_code": null, "province_name": null, "phone_number": null },
    "shipping_address": { "…": "same members as billing_address" },
    "items": [
        { "id": 60, "variant_code": "Cool_Breeze_Jeans-variant-4", "product_code": "Cool_Breeze_Jeans", "product_name": "Cool Breeze Jeans", "variant_name": "XXL", "quantity": 5, "unit_price": 8021, "total": 39105 }
    ],
    "payments": [ { "id": 20, "method_code": "bank_transfer", "state": "new", "amount": 39700, "currency_code": "USD" } ],
    "shipments": [ { "id": 20, "method_code": "dhl_express", "state": "ready", "tracking": null, "shipped_at": null } ],
    "notes": null
}
```

Never included: the order token (`tokenValue`), the customer IP, payment details.

### `customer.*` data (version 1)

```json
{
    "id": 13,
    "admin_url": "https://shop.example.com/admin/customers/13",
    "email": "aniyah@example.com",
    "first_name": "Aniyah",
    "last_name": "Larson",
    "phone_number": "1-520-372-9575",
    "gender": "f",
    "birthday": "1990-04-02",
    "group": "retail",
    "subscribed_to_newsletter": false,
    "has_account": true,
    "created_at": "2026-10-01T09:12:44+00:00",
    "changed_fields": ["last_name"]
}
```

`changed_fields` is present in `customer.updated` only. The shop user (password, salt, tokens,
roles) is never exposed: `has_account` tells whether there is one.

### `product_variant.stock_changed` data (version 1)

```json
{
    "variants": [
        { "id": 412, "code": "Cool_Breeze_Jeans-variant-4", "product_code": "Cool_Breeze_Jeans", "admin_url": "https://shop.example.com/admin/products/87/variants/412/edit", "tracked": true, "on_hand": 20, "on_hold": 5, "available": 15, "previous": { "on_hand": 20, "on_hold": 0 } }
    ]
}
```

### `webhook.ping` data (version 1)

```json
{ "endpoint_id": 3, "endpoint_name": "ERP", "message": "Ping from Sylius" }
```

## Receiving webhooks

Each request is a `POST` with `Content-Type: application/json` and three headers:
`webhook-id`, `webhook-timestamp` (Unix seconds) and `webhook-signature`
(`v1,<base64 HMAC-SHA256>`, possibly several separated by spaces). The signed content is
`{webhook-id}.{webhook-timestamp}.{raw body}`, the key is the base64-decoded part of the
`whsec_…` secret. Answer `2xx` quickly (within 15 s) and process asynchronously; any other
answer is retried, `410 Gone` disables the endpoint (its pending deliveries are suspended
until an administrator enables it again).

Any [Standard Webhooks library](https://github.com/standard-webhooks/standard-webhooks/tree/main/libraries)
works. Without a library:

**PHP**

```php
function verifyWebhook(string $body, array $headers, string $secret, int $tolerance = 300): array
{
    $id = $headers['webhook-id'] ?? '';
    $timestamp = $headers['webhook-timestamp'] ?? '';
    if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerance) {
        throw new RuntimeException('Invalid timestamp');
    }

    $key = base64_decode(substr($secret, strlen('whsec_')), true);
    $expected = 'v1,' . base64_encode(hash_hmac('sha256', "$id.$timestamp.$body", $key, true));
    foreach (explode(' ', $headers['webhook-signature'] ?? '') as $signature) {
        if (hash_equals($expected, $signature)) {
            return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        }
    }

    throw new RuntimeException('Invalid signature');
}

// $body must be the raw request body, before any JSON decoding.
$event = verifyWebhook(file_get_contents('php://input'), [
    'webhook-id' => $_SERVER['HTTP_WEBHOOK_ID'] ?? '',
    'webhook-timestamp' => $_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? '',
    'webhook-signature' => $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '',
], getenv('SYLIUS_WEBHOOK_SECRET'));
```

**Node.js** (Express, raw body)

```js
const crypto = require('node:crypto');
const express = require('express');

function verifyWebhook(rawBody, headers, secret, tolerance = 300) {
    const id = headers['webhook-id'];
    const timestamp = headers['webhook-timestamp'];
    if (!/^\d+$/.test(timestamp ?? '') || Math.abs(Date.now() / 1000 - Number(timestamp)) > tolerance) {
        throw new Error('Invalid timestamp');
    }

    const key = Buffer.from(secret.replace(/^whsec_/, ''), 'base64');
    const expected = Buffer.from('v1,' + crypto.createHmac('sha256', key).update(`${id}.${timestamp}.${rawBody}`).digest('base64'));
    const valid = (headers['webhook-signature'] ?? '').split(' ').some((signature) => {
        const given = Buffer.from(signature);
        return given.length === expected.length && crypto.timingSafeEqual(given, expected);
    });
    if (!valid) {
        throw new Error('Invalid signature');
    }

    return JSON.parse(rawBody);
}

const app = express();
app.post('/webhooks/sylius', express.raw({ type: 'application/json' }), (req, res) => {
    let event;
    try {
        event = verifyWebhook(req.body.toString('utf8'), req.headers, process.env.SYLIUS_WEBHOOK_SECRET);
    } catch (error) {
        return res.status(400).send(error.message);
    }
    queue.push(event); // process asynchronously
    res.sendStatus(204);
});
```

### Idempotency

Delivery is **at least once**: a timeout after your server processed the request, a worker
crash or an administrator's replay sends the same event again, always with the same
`webhook-id`. Store the ids you processed (a unique index is enough) and skip duplicates:

```sql
CREATE TABLE sylius_webhooks_received (webhook_id CHAR(36) PRIMARY KEY, received_at TIMESTAMP NOT NULL);
-- INSERT ... ; on duplicate key: already processed, answer 200 and do nothing.
```

Keep the ids at least as long as the retry window (the sum of `retry_schedule`: about 3 days
and 4 hours by default, up to 10% more with the jitter) plus the time an administrator may
replay (the retention, 30 days by default).

## Workers

`tmonier:webhook:dispatch` claims the due deliveries one by one (`SELECT … FOR UPDATE SKIP
LOCKED`, then a lease of `max_duration` + 60 s), sends them and records the result. Several
workers may run; one is enough for most shops.

| Option | |
|---|---|
| (none) | send what is due, then exit |
| `--loop` | keep polling (`--sleep` seconds when idle, default 1) |
| `--time-limit=N` | stop after N seconds |
| `--limit=N` | stop after N deliveries |

It finishes the delivery in progress on `SIGTERM` / `SIGINT`. A worker killed in the middle of
a request leaves a lease that expires; the delivery is then sent again (receivers deduplicate
with `webhook-id`).

**Cron** (every minute). The worker never starts a request later than `--time-limit` minus
`max_duration` minus 5 s (so `--time-limit` must be at least 35 s with the defaults), and
`flock -n` skips a run while the previous one is still busy:

```cron
* * * * * cd /var/www/shop && flock -n var/webhooks.lock php bin/console tmonier:webhook:dispatch --time-limit=55 >> var/log/webhooks.log 2>&1
```

**systemd** (`/etc/systemd/system/sylius-webhooks.service`):

```ini
[Unit]
Description=Sylius webhook outbox worker
After=network-online.target mysql.service

[Service]
User=www-data
WorkingDirectory=/var/www/shop
ExecStart=/usr/bin/php bin/console tmonier:webhook:dispatch --loop --time-limit=3600 --env=prod
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

**Supervisor**:

```ini
[program:sylius-webhooks]
command=php /var/www/shop/bin/console tmonier:webhook:dispatch --loop --time-limit=3600 --env=prod
user=www-data
autorestart=true
stopsignal=TERM
stopwaitsecs=40
```

`SKIP LOCKED` needs DBAL to know the real server version: when `doctrine.dbal.server_version`
is pinned to an old version (e.g. `5.7`, as in the Sylius test application), the worker falls
back to a plain `FOR UPDATE`. Deliveries are still claimed once, but concurrent workers wait
for each other: run a single worker, or set `server_version` to your real version
(`8.4.0`, `mariadb-11.4.0`, `17`).

## Commands

| Command | |
|---|---|
| `tmonier:webhook:dispatch` | the worker, see above |
| `tmonier:webhook:purge [--days=N] [--dry-run]` | deletes the events older than N days (default `retention_days`) with their deliveries; events with a delivery still pending or failed are kept |
| `tmonier:webhook:anonymize-customer <id or e-mail>` | redacts the personal data of the customer's events (payloads) and removes the response excerpts of their deliveries |

## Disabled endpoints

When an endpoint is disabled (by an administrator or after `410 Gone`), its pending and failed
deliveries become **suspended**: not attempted, not dead letters, and purgeable after the
retention. Enabling the endpoint again requeues them. Ping, replay and retry are refused while
the endpoint is disabled.

## Personal data

Webhook payloads contain personal data (names, e-mails, addresses). Before adding an endpoint,
qualify the receiver: your own ERP is not a third party; an iPaaS or a SaaS acting on your
behalf is a processor (GDPR art. 28, a data processing agreement is needed); a partner using
the data for its own purposes is an independent controller; a receiver outside the EEA is a
transfer (chapter V: adequacy decision such as the EU-US Data Privacy Framework, or standard
contractual clauses). Subscribe endpoints to the events they need only.

`tmonier:webhook:anonymize-customer` replaces the personal members (e-mail, names, phone,
address lines, notes, tracking codes...) by `null` and marks the events as anonymized.
**Anonymized events cannot be replayed**: resending a blanked payload under the same
`webhook-id` would either be ignored by the receiver or overwrite its data with blanks.
Retries still pending are sent with the redacted payload.

## Secrets

- Generated by the plugin (`whsec_` + 32 random bytes), shown **once**, in the answer to the
  creation or regeneration request (`Cache-Control: no-store`), never stored in the session,
  never displayed again, never logged.
- Stored in clear in `tmonier_webhook_endpoint.secret` (they are needed to sign): protect
  database dumps accordingly.
- Regenerating replaces the secret immediately (no overlap period in this version).
- Endpoint URLs are often credentials too (query tokens, Slack / Make / Zapier style paths).
  Logs, recorded errors and the admin grids and pages show them rebuilt without user info,
  without query string and without the path segments that look like tokens
  (`url_redaction_pattern`: 8+ characters mixing letters and digits, or 24+ characters).
  Only the endpoint edit form shows the full URL.

## Network safety

The deliveries use a dedicated HTTP client: private, loopback and link-local addresses are
refused after DNS resolution (`NoPrivateNetworkHttpClient`, symfony/http-client 6.4.15+ for
the CVE-2024-50342 fix), redirects are not followed, `http://` is refused unless
`allow_http_endpoints`. **Proxy environment variables (`HTTP_PROXY`, `HTTPS_PROXY`,
`ALL_PROXY`) are ignored by this client**: a proxy resolves the host itself and would bypass
the check. If your servers can only reach the Internet through a proxy, the webhooks cannot be
sent from them in this version.
- **With tmonier/sylius-audit-log-plugin**: the endpoint entity is not audited by default. If
  you add it to `tmonier_sylius_audit_log.resources`, keep the default
  `masked_field_patterns` (`/secret/i`): the trail then records that the secret changed, never
  its value. Do not add it with custom patterns that drop `/secret/i`.

## Adding event types from another plugin

1. Declare the type with a tagged provider (autoconfigured):

    ```php
    use Tmonier\SyliusWebhookOutboxPlugin\EventType\EventType;
    use Tmonier\SyliusWebhookOutboxPlugin\EventType\EventTypeProviderInterface;

    final class QuoteEventTypeProvider implements EventTypeProviderInterface
    {
        public function getEventTypes(): iterable
        {
            yield new EventType('quote.accepted', version: 1, label: 'app.webhook.quote_accepted');
        }
    }
    ```

   The type appears in the endpoint form and can be disabled in `events`.

2. Record the event **before flushing** the change it describes, so that both are written in
   the same transaction:

    ```php
    use Tmonier\SyliusWebhookOutboxPlugin\Outbox\OutboxRecorderInterface;
    use Tmonier\SyliusWebhookOutboxPlugin\Outbox\WebhookEvent;

    $quote->accept();
    $this->recorder->record(new WebhookEvent(
        'quote.accepted',
        ['id' => $quote->getId(), 'number' => $quote->getNumber(), 'total' => $quote->getTotal()],
        channelCode: $quote->getChannel()->getCode(),
        resourceType: 'quote',
        resourceId: $quote->getId(),
        customerId: $quote->getCustomer()?->getId(),   // for the erasure command
    ));
    $this->entityManager->flush();
    ```

   Or derive it from the unit of work like the core events: implement
   `Capture\ChangeDetectorInterface` (autoconfigured, called during `onFlush`) and use
   `LazyValue::idOf($entity)` for ids of entities inserted in the same flush. An e-invoicing
   plugin would emit `invoice.issued` the same way when its invoice gets a number.

## Customisation

- `WebhookSenderInterface`: replace the HTTP backend (Svix, symfony/webhook...).
- Models (`tmonier_sylius_webhook_outbox.models.*.classes`) and the endpoint form type can be
  overridden like any Sylius resource.
- Grids `tmonier_webhook_admin_webhook_endpoint`, `tmonier_webhook_admin_webhook_delivery`,
  `tmonier_webhook_admin_dead_letter` and the Twig Hooks `tmonier_webhook.admin.*` can be
  extended.

## How events are captured

During Doctrine's `onFlush`, detectors read the change sets (`checkoutState → completed`...),
and the recorder `persist()`s the event and its deliveries and calls `computeChangeSet()`:
Doctrine inserts them in the transaction it opens right after, together with the order. Ids of
entities inserted in the same flush (a new customer, the order items) are resolved when the
event row is inserted, after them. See [SPEC.md](SPEC.md) for the spike that validated this on
ORM 2.20 and 3.6, and the alternatives that were rejected.

## Limitations

- Changes made without the Doctrine ORM (DBAL or SQL queries, bulk DQL `UPDATE`) produce no
  event.
- At-least-once delivery, no ordering guarantee between events (no per-endpoint serial queue).
- Webhooks cannot go through an HTTP proxy (see [Network safety](#network-safety)).
- Capture errors (a bug in a normalizer) are logged as critical and do not block the checkout:
  the business change wins.
- One secret per endpoint, no overlap when regenerating; no ed25519 (`v1a`) signatures.
- No rate limiting per endpoint, no automatic disabling after N failures (only on `410 Gone`),
  no alert e-mails, no metrics.
- No incoming webhooks, no admin API.
- Sylius Plus RBAC is not integrated: every administrator can manage webhooks.

## Development

```bash
docker compose up -d mysql mailhog   # or your own MySQL
composer install
(cd vendor/sylius/test-application && yarn install && yarn build)
vendor/bin/console doctrine:database:create
vendor/bin/console doctrine:migrations:migrate -n
vendor/bin/console sylius:fixtures:load -n
vendor/bin/console tmonier:webhook:demo     # demo endpoints and deliveries (test application only)

vendor/bin/phpunit --testsuite unit
APP_ENV=test vendor/bin/phpunit --testsuite non-unit   # needs a test database with fixtures
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

`tests/Integration/TransactionalOutboxTest` commits for real (no wrapping transaction) and
cleans up after itself; set `DATABASE_SERVER_VERSION` (e.g. `8.4.0`) to test `SKIP LOCKED`.

## License

MIT. See [LICENSE](LICENSE).
