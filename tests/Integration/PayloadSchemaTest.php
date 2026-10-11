<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Integration;

use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Order\Factory\OrderItemUnitFactoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusWebhookOutboxPlugin\WebhookTestTrait;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\EnvelopeFactory;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\OutboxRecorderInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\WebhookEvent;

/**
 * The payloads match the JSON Schemas published in docs/schemas (the README documents them):
 * same members, types and nullability. A small validator for the subset of JSON Schema used.
 */
final class PayloadSchemaTest extends KernelTestCase
{
    use WebhookTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginTestTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBackTestTransaction();
        parent::tearDown();
    }

    public function testEveryCoreEventMatchesItsSchema(): void
    {
        $endpoint = $this->createEndpoint();
        $customer = $this->newCustomer();
        $order = $this->newCompletedOrder($customer);
        $variant = $this->trackedVariant();
        /** @var FactoryInterface<OrderItemInterface> $itemFactory */
        $itemFactory = self::getContainer()->get('sylius.factory.order_item');
        $item = $itemFactory->createNew();
        $item->setVariant($variant);
        $item->setUnitPrice(1999);
        $order->addItem($item);
        $unitFactory = self::getContainer()->get('sylius.factory.order_item_unit');
        self::assertInstanceOf(OrderItemUnitFactoryInterface::class, $unitFactory);
        $unitFactory->createForItem($item);
        $unitFactory->createForItem($item);
        $this->entityManager()->persist($customer);
        $this->entityManager()->persist($order);
        $this->entityManager()->flush();

        $customer->setLastName('King');
        $variant->setOnHand((int) $variant->getOnHand() + 3);
        $this->entityManager()->flush();

        $recorder = self::getContainer()->get(OutboxRecorderInterface::class);
        self::assertInstanceOf(OutboxRecorderInterface::class, $recorder);
        $recorder->record(new WebhookEvent('webhook.ping', ['endpoint_id' => $endpoint->getId(), 'endpoint_name' => $endpoint->getName(), 'message' => 'Ping from Sylius']), $endpoint);
        $this->entityManager()->flush();

        $checked = [
            'order.placed' => $this->eventsOfType('order.placed', (string) $this->idOf($order)),
            'customer.created' => $this->eventsOfType('customer.created', (string) $this->idOf($customer)),
            'customer.updated' => $this->eventsOfType('customer.updated', (string) $this->idOf($customer)),
            'product_variant.stock_changed' => $this->eventsOfType('product_variant.stock_changed'),
            'webhook.ping' => $this->eventsOfType('webhook.ping'),
        ];
        $schemas = [
            'order.placed' => 'order.v1',
            'customer.created' => 'customer.v1',
            'customer.updated' => 'customer.v1',
            'product_variant.stock_changed' => 'product_variant.stock_changed.v1',
            'webhook.ping' => 'webhook.ping.v1',
        ];

        foreach ($checked as $type => $events) {
            self::assertCount(1, $events, $type);
            $envelope = json_decode(EnvelopeFactory::encode($events[0]), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertMatchesSchema($envelope, $this->schema('envelope'), $type);
            self::assertIsArray($envelope);
            $this->assertMatchesSchema($envelope['data'], $this->schema($schemas[$type]), $type . '.data');
        }

        $order = $checked['order.placed'][0]->getPayload();
        self::assertIsArray($order['items']);
        self::assertCount(1, $order['items']);
        self::assertIsArray($order['items'][0]);
        self::assertSame(2, $order['items'][0]['quantity']);
        self::assertSame($variant->getCode(), $order['items'][0]['variant_code']);
        self::assertIsInt($order['items'][0]['id'], 'Id of an item inserted in the same flush.');
    }

    /** @return array<string, mixed> */
    private function schema(string $name): array
    {
        $schema = json_decode((string) file_get_contents(sprintf('%s/../../docs/schemas/%s.json', __DIR__, $name)), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);
        $named = [];
        foreach ($schema as $key => $value) {
            $named[(string) $key] = $value;
        }

        return $named;
    }

    /** @param array<string, mixed> $schema */
    private function assertMatchesSchema(mixed $value, array $schema, string $path): void
    {
        $types = (array) ($schema['type'] ?? []);
        $actual = match (true) {
            null === $value => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_string($value) => 'string',
            is_array($value) && array_is_list($value) && [] !== $value => 'array',
            is_array($value) => [] === $value && in_array('array', $types, true) ? 'array' : 'object',
            default => get_debug_type($value),
        };
        self::assertContains($actual, $types, sprintf('%s: %s not allowed', $path, $actual));

        if ('object' === $actual && is_array($value)) {
            /** @var array<string, array<string, mixed>> $properties */
            $properties = $schema['properties'] ?? [];
            /** @var list<string> $required */
            $required = $schema['required'] ?? [];
            foreach ($required as $name) {
                self::assertArrayHasKey($name, $value, sprintf('%s.%s is required', $path, $name));
            }
            if (false === ($schema['additionalProperties'] ?? true)) {
                self::assertSame([], array_values(array_diff(array_keys($value), array_keys($properties))), $path . ': undocumented members');
            }
            foreach ($value as $name => $member) {
                if (isset($properties[$name])) {
                    $this->assertMatchesSchema($member, $properties[$name], $path . '.' . $name);
                }
            }
        }

        if ('array' === $actual && is_array($value) && isset($schema['items']) && is_array($schema['items'])) {
            /** @var array<string, mixed> $items */
            $items = $schema['items'];
            foreach ($value as $index => $item) {
                $this->assertMatchesSchema($item, $items, sprintf('%s[%d]', $path, $index));
            }
        }
    }
}
