# CLAUDE.md

Sylius 2 plugin sending outgoing webhooks through a transactional outbox: events captured in the
transaction of the business change, Standard Webhooks signatures, database-driven retries, dead
letters and replay in the admin. Functional spec, spike and decisions: `SPEC.md`. Public docs:
`README.md`.

## Commands

PHP needs more than 128 MB to compile the test application container: prefix console and
PHPStan calls with `php -d memory_limit=2G` when running locally.

```bash
docker compose up -d mysql mailhog        # ports come from compose.override.yml (gitignored)
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite non-unit   # functional + integration, need the test DB + Sylius fixtures
vendor/bin/phpstan analyse                # level max, src + tests
vendor/bin/ecs check
vendor/bin/console lint:container
vendor/bin/console tmonier:webhook:demo -e dev   # demo endpoints / deliveries (test application command)
```

Local env overrides (gitignored): `compose.override.yml` (MySQL published on 3323, MailHog on
8042/1042), `tests/TestApplication/.env.local` (DATABASE_URL, MAILER_DSN,
DATABASE_SERVER_VERSION) and `.env.test.local` (DATABASE_URL, DATABASE_SERVER_VERSION=8.4.0).
Databases: `tmonier_sylius_webhook_outbox_dev` / `_test`. The kernel cache lives in `var/cache`
(plugin root). Dev server: `symfony server:start -d --port=8089 --no-tls` (admin `sylius` /
`sylius`).

## Architecture

- `src/Capture/EntityGuard` — detectors wrap each entity: a failure is logged with the entity
  id and the other entities of the flush keep their events.
- `src/Capture/OutboxCaptureListener` — `onFlush`: runs the tagged `ChangeDetectorInterface`
  services (`Capture/Detector/*`), the recorder `persist()`s + `computeChangeSet()`s the event
  and its deliveries. `postPersist` of an event: one DBAL `UPDATE` of `resource_id` /
  `customer_id` when they were unknown (new entities). Keep this: no DBAL in `onFlush` (before
  `beginTransaction`), nothing in `postFlush` (after the commit).
- `src/Outbox` — `OutboxRecorder` (public API `OutboxRecorderInterface`, fan-out to subscribed
  endpoints), `WebhookEvent`, `DeferredPayload` + `LazyValue` (ids resolved when Doctrine
  encodes the JSON at insert time; never memoise null).
- `src/Payload` — own versioned DTO normalizers. Any field change must update
  `docs/schemas/*.json` (checked by `PayloadSchemaTest`) and the README. Never expose tokens,
  passwords, the order token or the customer IP.
- `src/Delivery` — `DeliveryClaimer` (plain candidate read, then SKIP LOCKED on one primary
  key + lease; FOR UPDATE when DBAL says unsupported), `WebhookDispatcher` (entity manager
  from the registry; on error: release the claim, reset a closed manager; re-read under the
  row lock before recording), `HttpWebhookSender`, `RetryPolicy`, `EnvelopeFactory`.
- `src/EventListener/EndpointAvailabilityListener` — disabling an endpoint suspends its open
  deliveries, enabling it requeues them (postUpdate, DBAL, inside the transaction).
- `src/Http` — SSRF client factory (`NoPrivateNetworkHttpClient`, no redirect, env proxies
  ignored), CIDR subtraction, URL policy, `UrlRedactor` service (parse and rebuild; use it for
  anything that shows or logs a URL).
- `src/Signature` — Standard Webhooks signer and `whsec_` generator.
- `src/Controller/Admin`, `src/EventListener/EndpointSecretListener` — the secret is shown only
  in the response to the create / regenerate POST (`SecretRevealer`, no-store); never flash,
  log, or render it elsewhere.
- `src/Maintenance` — payload redaction and customer anonymization (blocks replays).
- `tests/TestApplication/src/Http/WebhookReceiverStub` — MockHttpClient callback used in the
  test env behind the real SSRF guard; use public IP literals (93.184.215.14) in tests, no DNS.

## Rules

- The outbox guarantee is tested with real commits in `tests/Integration/TransactionalOutboxTest`
  (no wrapping transaction, cleans up in tearDown). Other tests run in a rolled-back transaction.
- Sylius fixtures are random: create customers, orders and endpoints in the state a test needs.
- After a request in a functional test (kernel kept), entities are detached: reload them with
  `find()` instead of `refresh()`.
- Model class parameters `%tmonier_webhook.model.*.class%` are set in `prepend()`.
- Migrations use the Schema API (MySQL, MariaDB, PostgreSQL) and contain plugin tables only.
- Every translation key must exist in en, fr, de, es, it, nl, pl for the messages, flashes and
  validators domains (`TranslationsTest`). Clear `var/cache` after adding a translation file.
- Dates are stored in UTC without time zone; getters read them back as UTC.
- MySQL reorders JSON object members: compare decoded arrays, never JSON strings from the DB.
