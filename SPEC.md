# Functional specification — webhook outbox

Outgoing webhooks for Sylius 2 with a transactional outbox: events written in the transaction
of the business change, Standard Webhooks signatures, database-driven retries, dead letters,
replay from the admin panel. Research and spike date: 2026-10-11.

## 1. Existing solutions

| Package | Why not |
|---|---|
| `setono/sylius-webhook-plugin` | **Incoming** webhooks only (payment providers calling Sylius): not a competitor. [GitHub](https://github.com/Setono/SyliusWebhookPlugin/tree/master/src) |
| `goetas/messenger-doctrine-outbox` | Never tagged; does not install on Symfony 7.4 / 8. |
| `symfony/webhook` | Generic sender over Messenger, no persistence of attempts, no replay, no admin; the Standard Webhooks signature is expected in Symfony 8.2 (around November 2026, UNVERIFIED). Our edge is persistence, replay, atomic capture and the Sylius event catalogue, not the signature. A `WebhookSenderInterface` backend can use it later. |
| `zenstruck/messenger-monitor-bundle`, `rekalogika/domain-event-outbox`, Svix (self-hosted or SaaS) | Building blocks to assemble yourself; none knows Sylius events, none gives a Sylius admin. [Packagist](https://packagist.org/packages/zenstruck/messenger-monitor-bundle) · [Svix](https://github.com/svix/svix-webhooks) |

No outgoing webhook plugin exists for Sylius 2.

## 2. Spike (done first, 2026-10-11)

Questions: (1) can an `onFlush` listener `persist()` a new entity and `computeChangeSet()` it so
that Doctrine inserts it in the transaction of the flush, on ORM 2.20 and 3.x? (2) is
`QueryBuilder::forUpdate(SKIP_LOCKED)` available on the DBAL versions allowed by Sylius
2.2 (`doctrine/dbal ^3.9`, `doctrine/orm ^2.18 || ^3.5`) and 2.3 (`^3.10 || ^4.4`,
`^2.20 || ^3.6`)?

Script: [`docs/spike/onflush_capture_spike.php`](docs/spike/onflush_capture_spike.php) (plain
Doctrine, attribute-mapped entities, two independent connections to observe commits).

| | ORM 2.20.13 + DBAL 3.9.5 | ORM 3.6.9 + DBAL 4.5.0 |
|---|---|---|
| MySQL 8.4.11 | 9/9 pass | 9/9 pass |
| MariaDB 11.8.8 | 9/9 pass | 9/9 pass |
| PostgreSQL 17.11 | 9/9 pass | 9/9 pass |

Checks: event + delivery committed with an update (`state cart → new`); ids of entities
inserted in the same flush resolved in the payload; row visible inside an explicit transaction
and absent from a second connection after `rollBack()`; nothing committed when the flush fails
on a unique index *after* the event insert; `FOR UPDATE SKIP LOCKED` lets a second connection
claim another row instead of waiting.

Findings that shaped the design:

1. **`persist()` + `computeChangeSet()` in `onFlush` works** on both ORM lines: ORM ≥ 2.16 orders
   insertions per entity (topological sort keeping the scheduling order), so the event, scheduled
   last, is inserted after the entities it refers to.
2. **Ids of new entities are unknown during `onFlush`** (IDENTITY columns: a new customer, the
   items of an order created completed). Building the payload eagerly gives `"id": null`.
   Solution: the payload is a `DeferredPayload` (`JsonSerializable`) holding `LazyValue`
   references; Doctrine's `json` type encodes it when the event row is inserted, i.e. after the
   referenced rows. Two indexed columns (`resource_id`, `customer_id`) cannot be lazy: they are
   written by one `UPDATE` in the event's `postPersist` (inside the transaction, same
   connection) and synchronised with the unit of work (`setOriginalEntityProperty`).
   `LazyValue` must not memoise `null` (first implementation bug, caught by the real-commit test).
3. **`SKIP LOCKED` exists in DBAL 3.9 and 4** (`ConflictResolutionMode::SKIP_LOCKED`: a class
   constant in 3.x, an enum case in 4.x, the same source compiles on both) for MySQL 8+,
   MariaDB 10.6+ and PostgreSQL 10+. **But DBAL derives support from the configured
   `server_version`**: with `5.7` (pinned by the Sylius test application, common in projects)
   it throws `NotSupported`. The claimer detects it and falls back to `FOR UPDATE` (correct,
   serialised). The test application reads `DATABASE_SERVER_VERSION`, set per database in CI.

No product decision was needed: the spike passed and only added the lazy references.

## 3. Scope

v1, session 1 (core): entities `WebhookEndpoint`, `OutboxEvent` (UUIDv7 = `webhook-id`),
`WebhookDelivery` (last 10 attempts in JSON); capture of `order.placed`, `order.paid`,
`order.cancelled`, `customer.created` + `webhook.ping`; versioned envelope; Standard Webhooks
signature (≈ 40 lines, specification vectors); retries (immediately, 5 s, 5 min, 30 min, 2 h,
5 h, 10 h, 14 h, 20 h, 24 h + jitter), `410` disables, `Retry-After` on 429/502/503/504, dead
letters; `tmonier:webhook:dispatch` (`--loop`, `--time-limit`) and `tmonier:webhook:purge`;
SSRF protection, https only, 15 s timeout, 30 s maximum duration.

v1, session 2 (admin): endpoint grid and CRUD (secret shown once, regenerate), deliveries grid
with filters and dead-letter view, delivery page (request, response truncated to 2 KB,
attempts), Replay (single and bulk), Retry now, Ping; `order.shipped`, `customer.updated`
(whitelist), `product_variant.stock_changed` coalesced per flush; anonymization of payloads and
response excerpts; receiver docs (PHP, Node) and idempotency guide.

Out of scope: incoming webhooks, full API Platform payloads, ordered (serial) delivery,
ed25519 `v1a` signatures, admin API, Kafka / RabbitMQ / CloudEvents, alert e-mails or Slack,
per-endpoint rate limiting, metrics. v1.1: delivery workflow graph, auto-disable after N
failures, Messenger / Scheduler handler, secret rotation with overlap.

## 4. Design

### Capture

`OutboxCaptureListener::onFlush` runs the tagged `ChangeDetectorInterface` services (order
states, customer, stock) on the change sets; `OutboxRecorder` creates the event and one
delivery per enabled endpoint subscribed to the type and accepting the channel, `persist()`s
them and calls `computeChangeSet()`.

- No DBAL write in `onFlush`: in ORM 3.6.9 `onFlush` is dispatched **before**
  `beginTransaction()`, the insert would be auto-committed outside the transaction.
- No write in `postFlush`: it runs after the commit (the audit-log plugin does that on purpose;
  an outbox cannot).
- Works for every entry point (shop, API, admin, CLI), unlike a buffer filled by workflow
  listeners, and without buffer leaks on rollback or `clear()`: nothing is kept outside the
  unit of work.
- No endpoint subscribed → nothing stored.
- A detector exception is logged as critical and swallowed: the checkout is never broken by a
  webhook bug (documented trade-off).
- `product_variant.stock_changed` is coalesced per flush (placing an order holds stock on
  every line); `customer.updated` only fires for a whitelist of fields.

Public API for other plugins: `OutboxRecorderInterface::record(WebhookEvent)` before their own
flush; event types via tagged `EventTypeProviderInterface` (e.g. RFQ `quote.accepted`,
Factur-X `invoice.issued`).

### Payloads

Own normalizers (`Payload\*Normalizer`), stable versioned DTOs, JSON Schemas in
`docs/schemas` checked by `PayloadSchemaTest`. No API Platform dump (unstable, huge, leaks
internals); no order token, customer IP, password, salt or user token. `admin_url` instead of an
API IRI (Sylius admin API order IRIs contain the order token).

### Delivery

- `DeliveryClaimer`: one due delivery at a time; enabled endpoint ids read first (locking a
  join would lock endpoint rows), then `SELECT … ORDER BY next_attempt_at LIMIT 1 FOR UPDATE
  SKIP LOCKED` and a lease (`claimed_until` = now + `max_duration` + 60 s, `claim_token`).
  The result is written only if the token still matches (fencing).
- `HttpWebhookSender` (default `WebhookSenderInterface`): `NoPrivateNetworkHttpClient`
  (checks the resolved IP since CVE-2024-50342) on a dedicated client, `max_redirects: 0`,
  timeouts; reads at most `response_body_max_bytes`; error messages and logs strip the query
  string and user info of the URL.
- `allowed_private_networks`: CIDR subtraction (`SubnetCalculator`) from Symfony's private
  ranges, enforcement stays in `NoPrivateNetworkHttpClient`.
- `RetryPolicy`: schedule from the configuration, jitter, `Retry-After` (delta or HTTP date,
  capped at 24 h, never shorter than the schedule).
- Body: envelope JSON built from the event row at send time; signature per attempt (fresh
  timestamp). On MySQL the JSON column reorders object members: documented, receivers parse.

### Admin

Sylius resources and grids, generic CRUD templates, Twig Hooks for the delivery page and the
secret page, menu group "Integrations". The secret is generated in `pre_create` and shown by
the `post_create` response (no redirect, `no-store`), so it never transits through the session
or a URL. Retry now / replay never send from the HTTP request: the worker does.

### Replay and anonymization

A replay is a new delivery of the same event (same `webhook-id`). Anonymization redacts the
event payload and the response excerpts, and **blocks replays** of that event: a blanked body
under an already-processed id is either ignored or harmful (it would overwrite the receiver's
data with blanks). Pending retries still go out, redacted.

### GDPR

Roles depend on the receiver: the merchant's ERP is not a third party; an iPaaS is a processor
(art. 28, DPA); a partner may be an independent controller; endpoints outside the EEA are
transfers (chapter V: adequacy such as the DPF, or SCCs). Retention via `purge`, erasure via
`anonymize-customer` (`customer_id` column).

### Review fixes (2026-10-11)

An adversarial code review (19 findings) led to these decisions:

- **Worker recovery**: any exception while recording an attempt closes the entity manager
  (`wrapInTransaction`). The dispatcher releases the claim and resets the manager through the
  `ManagerRegistry`, so a `--loop` worker keeps working; it gets its entity manager from the
  registry each time.
- **Erasure vs. write-back**: the attempt is recorded after re-reading the delivery and its
  event under the row lock; the response excerpt of an anonymized event is dropped. The
  anonymizer locks the delivery rows (`PESSIMISTIC_WRITE`) before erasing.
- **Disabled endpoints**: their open deliveries become `suspended` (purgeable, not dead
  letters), requeued when the endpoint is enabled again; ping, replay and retry are refused.
- **Claim locking**: a plain read of candidates per status along the
  `(status, next_attempt_at, id)` index, then `FOR UPDATE SKIP LOCKED` on one primary key, so
  that a claim locks one row and never blocks the checkout inserts or the other workers.
- **Network**: environment proxies are ignored by the delivery client (a proxy resolves the
  host itself); `symfony/http-client` and `http-foundation` require 6.4.15+ (CVE-2024-50342
  fix and the full private ranges).
- **Redaction**: URLs are parsed and rebuilt everywhere (messages included, whatever the
  encoding), the query is always hidden, path segments that look like tokens too
  (`url_redaction_pattern`).
- **Capture**: per-entity isolation of normalization errors (`EntityGuard`, entity id logged);
  `DeferredPayload` never caches unresolved ids; new tracked variants publish their initial
  stock; a former guest creating an account gives `customer.updated` with `has_account`;
  event ids are strictly increasing within a process (receivers sort by id, the timestamp has
  a one-second precision).
- `Retry-After` accepts the three HTTP-date formats of RFC 9110; the cron run never starts a
  request that could outlive `--time-limit` (and the README uses `flock`).

## 5. Tests

- Unit: signer (specification vectors), retry policy, subnet calculation, SSRF client
  (127.0.0.1, 169.254.169.254, 192.168/16, ::1 blocked; allowed hole), sender (truncation,
  Retry-After, transport errors without credentials, no redirect), lazy payloads, entities,
  configuration, registry, redactor, translations.
- Integration: **real commits and rollbacks** (`TransactionalOutboxTest`: commit, explicit
  rollback, flush failing after the event insert, concurrent claim with `SKIP LOCKED`, claim
  not blocking inserts, worker recovery after a failed record, lost lease, erasure during the
  request), capture rules, worker
  (`MockHttpClient` through the real SSRF guard: 200, 500, 429 + Retry-After, 410, timeout,
  private networks, http refused, exhaustion, expired / live leases, command), purge,
  anonymization, payload schemas.
- Functional: admin pages (access, one-time secret, URL validation, regenerate, ping, CSRF,
  grids, dead letters, delivery page, retry, replay, bulk replay, translations).
- CI: the 8-job matrix of the sibling plugins (MySQL 8.4, MariaDB 11.4 on Sylius 2.2,
  PostgreSQL 17 on Sylius 2.3), with `DATABASE_SERVER_VERSION` per database.

## 6. Sources

- Standard Webhooks specification: https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md
- Doctrine ORM events, `onFlush`: https://www.doctrine-project.org/projects/doctrine-orm/en/3.3/reference/events.html#onflush
- CVE-2024-50342, `NoPrivateNetworkHttpClient`: https://symfony.com/blog/cve-2024-50342-internal-address-and-port-enumeration-allowed-by-noprivatenetworkhttpclient
- Setono webhook plugin (incoming): https://github.com/Setono/SyliusWebhookPlugin/tree/master/src
- zenstruck/messenger-monitor-bundle: https://packagist.org/packages/zenstruck/messenger-monitor-bundle
- Svix: https://github.com/svix/svix-webhooks
- Doctrine DBAL `ConflictResolutionMode` (3.9 and 4.x sources in `vendor/doctrine/dbal/src/Query/ForUpdate`)
- GDPR art. 28 and chapter V (Regulation (EU) 2016/679)
