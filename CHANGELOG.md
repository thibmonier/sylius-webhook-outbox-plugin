# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

- Health probes for the [platform health plugin](https://github.com/thibmonier/sylius-platform-health-plugin):
  `webhook_outbox.backlog` (age of the oldest due pending delivery: warning above 15 minutes, critical above 60)
  and `webhook_outbox.dead_deliveries` (dead deliveries and endpoints disabled automatically). The health plugin is
  only suggested (`suggest`), never required: the probes are loaded only when it is installed.
- `WebhookDeliveryRepositoryInterface` gains `oldestPendingDueAt()` and `countDeadUpTo()`,
  `WebhookEndpointRepositoryInterface` gains `countDisabledAutomatically()` (read-only aggregate queries).
