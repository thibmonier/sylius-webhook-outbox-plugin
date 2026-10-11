<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the webhook endpoint, outbox event and delivery tables (tmonier/sylius-webhook-outbox-plugin).';
    }

    public function up(Schema $schema): void
    {
        $endpoint = $schema->createTable('tmonier_webhook_endpoint');
        $endpoint->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $endpoint->addColumn('name', Types::STRING, ['length' => 255]);
        $endpoint->addColumn('url', Types::STRING, ['length' => 2048]);
        $endpoint->addColumn('description', Types::TEXT, ['notnull' => false]);
        $endpoint->addColumn('secret', Types::STRING, ['length' => 255]);
        $endpoint->addColumn('enabled', Types::BOOLEAN);
        $endpoint->addColumn('event_types', Types::JSON);
        $endpoint->addColumn('channel_codes', Types::JSON);
        $endpoint->addColumn('disabled_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $endpoint->addColumn('disabled_reason', Types::STRING, ['length' => 255, 'notnull' => false]);
        $endpoint->addColumn('created_at', Types::DATETIME_MUTABLE);
        $endpoint->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $endpoint->setPrimaryKey(['id']);

        $event = $schema->createTable('tmonier_webhook_outbox_event');
        $event->addColumn('id', Types::STRING, ['length' => 36]);
        $event->addColumn('type', Types::STRING, ['length' => 100]);
        $event->addColumn('version', Types::INTEGER);
        $event->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $event->addColumn('channel_code', Types::STRING, ['length' => 255, 'notnull' => false]);
        $event->addColumn('resource_type', Types::STRING, ['length' => 64, 'notnull' => false]);
        $event->addColumn('resource_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $event->addColumn('customer_id', Types::INTEGER, ['notnull' => false]);
        $event->addColumn('payload', Types::JSON);
        $event->addColumn('anonymized_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $event->setPrimaryKey(['id']);
        $event->addIndex(['occurred_at'], 'tmonier_webhook_outbox_event_occurred_at_idx');
        $event->addIndex(['type'], 'tmonier_webhook_outbox_event_type_idx');
        $event->addIndex(['resource_type', 'resource_id'], 'tmonier_webhook_outbox_event_resource_idx');
        $event->addIndex(['customer_id'], 'tmonier_webhook_outbox_event_customer_idx');

        $delivery = $schema->createTable('tmonier_webhook_delivery');
        $delivery->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $delivery->addColumn('event_id', Types::STRING, ['length' => 36]);
        $delivery->addColumn('endpoint_id', Types::INTEGER);
        $delivery->addColumn('status', Types::STRING, ['length' => 16]);
        $delivery->addColumn('attempts', Types::INTEGER);
        $delivery->addColumn('next_attempt_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $delivery->addColumn('last_attempt_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $delivery->addColumn('delivered_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $delivery->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $delivery->addColumn('last_response_status', Types::INTEGER, ['notnull' => false]);
        $delivery->addColumn('last_response_body', Types::TEXT, ['notnull' => false]);
        $delivery->addColumn('last_error', Types::TEXT, ['notnull' => false]);
        $delivery->addColumn('attempt_history', Types::JSON);
        $delivery->addColumn('claimed_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $delivery->addColumn('claim_token', Types::STRING, ['length' => 64, 'notnull' => false]);
        $delivery->addColumn('replay_of', Types::INTEGER, ['notnull' => false]);
        $delivery->setPrimaryKey(['id']);
        $delivery->addIndex(['status', 'next_attempt_at'], 'tmonier_webhook_delivery_due_idx');
        $delivery->addIndex(['created_at'], 'tmonier_webhook_delivery_created_at_idx');
        $delivery->addIndex(['event_id'], 'IDX_38E5E40C71F7E88B');
        $delivery->addIndex(['endpoint_id'], 'IDX_38E5E40C21AF7E36');
        $delivery->addForeignKeyConstraint('tmonier_webhook_outbox_event', ['event_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_38E5E40C71F7E88B');
        $delivery->addForeignKeyConstraint('tmonier_webhook_endpoint', ['endpoint_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_38E5E40C21AF7E36');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_webhook_delivery');
        $schema->dropTable('tmonier_webhook_outbox_event');
        $schema->dropTable('tmonier_webhook_endpoint');
    }
}
