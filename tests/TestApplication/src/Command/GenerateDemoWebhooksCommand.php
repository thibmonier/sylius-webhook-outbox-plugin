<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\DeliveryAttempt;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpoint;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\CoreEventTypeProvider;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\OutboxRecorderInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\WebhookEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\CustomerPayloadNormalizer;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\OrderPayloadNormalizer;
use Tmonier\SyliusWebhookOutboxPlugin\Signature\SecretGenerator;

/**
 * Development helper: endpoints and deliveries in every state for the admin panel, without
 * network (the attempts are simulated). bin/console tmonier:webhook:demo -e dev
 */
#[AsCommand(name: 'tmonier:webhook:demo', description: 'Create demo webhook endpoints and deliveries (development only)')]
final class GenerateDemoWebhooksCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OutboxRecorderInterface $recorder,
        private readonly OrderPayloadNormalizer $orderNormalizer,
        private readonly CustomerPayloadNormalizer $customerNormalizer,
        private readonly SecretGenerator $secretGenerator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $erp = $this->endpoint('ERP (orders)', 'https://erp.example.com/webhooks/sylius', ['order.placed', 'order.paid', 'order.cancelled', 'order.shipped'], true);
        $crm = $this->endpoint('CRM (customers)', 'https://crm.example.org/hooks/sylius?account=demo', ['customer.created', 'customer.updated'], true);
        $legacy = $this->endpoint('Legacy middleware', 'https://legacy.example.net/sylius', ['order.placed', 'product_variant.stock_changed'], false);
        $legacy->disable('410 Gone', new \DateTimeImmutable('-2 days'));
        $this->entityManager->flush();

        /** @var list<OrderInterface> $orders */
        $orders = $this->entityManager->getRepository(OrderInterface::class)->findBy(['checkoutState' => 'completed'], ['id' => 'DESC'], 8);
        /** @var list<CustomerInterface> $customers */
        $customers = $this->entityManager->getRepository(CustomerInterface::class)->findBy([], ['id' => 'DESC'], 4);

        $deliveries = [];
        foreach ($orders as $order) {
            $event = $this->recorder->record(new WebhookEvent('order.placed', $this->orderNormalizer->normalize($order), $order->getChannel()?->getCode(), 'order', $order->getId(), $order->getCustomer()?->getId()), $erp);
            $this->entityManager->flush();
            if (null !== $event) {
                $deliveries = [...$deliveries, ...$this->entityManager->getRepository(WebhookDeliveryInterface::class)->findBy(['event' => $event])];
            }
        }
        foreach ($customers as $customer) {
            $event = $this->recorder->record(new WebhookEvent('customer.created', $this->customerNormalizer->normalize($customer), null, 'customer', $customer->getId(), $customer->getId()), $crm);
            $this->entityManager->flush();
            if (null !== $event) {
                $deliveries = [...$deliveries, ...$this->entityManager->getRepository(WebhookDeliveryInterface::class)->findBy(['event' => $event])];
            }
        }
        $this->recorder->record(new WebhookEvent(CoreEventTypeProvider::WEBHOOK_PING, ['endpoint_id' => $legacy->getId(), 'message' => 'Ping from Sylius'], null, 'webhook_endpoint', $legacy->getId()), $legacy);
        $this->entityManager->flush();

        $now = new \DateTimeImmutable();
        foreach ($deliveries as $index => $delivery) {
            /** @var WebhookDeliveryInterface $delivery */
            switch ($index % 4) {
                case 0:
                case 1:
                    $delivery->recordAttempt(new DeliveryAttempt($now->modify('-' . (10 + $index) . ' minutes'), 120 + $index, 200, '{"received":true}'));
                    $delivery->markDelivered($now->modify('-' . (10 + $index) . ' minutes'));

                    break;
                case 2:
                    $delivery->recordAttempt(new DeliveryAttempt($now->modify('-6 minutes'), 15000, null, null, 'Idle timeout reached for "https://erp.example.com/webhooks/sylius".'));
                    $delivery->recordAttempt(new DeliveryAttempt($now->modify('-1 minute'), 340, 503, '{"error":"maintenance"}', null, 300));
                    $delivery->scheduleRetry($now->modify('+5 minutes'));

                    break;
                default:
                    for ($attempt = 10; $attempt > 0; --$attempt) {
                        $delivery->recordAttempt(new DeliveryAttempt($now->modify(sprintf('-%d hours', $attempt * 6)), 80, 500, '<html><body>Internal Server Error</body></html>'));
                    }
                    $delivery->markDead();
            }
        }
        $this->entityManager->flush();

        $output->writeln(sprintf('3 endpoints and %d deliveries created (secrets are random, attempts simulated).', count($deliveries) + 1));

        return Command::SUCCESS;
    }

    /** @param list<string> $eventTypes */
    private function endpoint(string $name, string $url, array $eventTypes, bool $enabled): WebhookEndpoint
    {
        $endpoint = new WebhookEndpoint();
        $endpoint->setName($name);
        $endpoint->setUrl($url);
        $endpoint->setSecret($this->secretGenerator->generate());
        $endpoint->setEventTypes($eventTypes);
        $endpoint->setEnabled($enabled);
        $this->entityManager->persist($endpoint);

        return $endpoint;
    }
}
