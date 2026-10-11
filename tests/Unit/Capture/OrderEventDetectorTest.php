<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\Capture;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\Detector\OrderEventDetector;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\EntityGuard;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\AdminUrlGenerator;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\CustomerPayloadNormalizer;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\OrderPayloadNormalizer;

final class OrderEventDetectorTest extends TestCase
{
    public function testAFailingOrderDoesNotDropTheOthersOfTheFlush(): void
    {
        $broken = $this->createMock(OrderInterface::class);
        $broken->method('getId')->willReturn(7);
        $broken->method('getNumber')->willThrowException(new \RuntimeException('Broken proxy'));
        $fine = $this->createMock(OrderInterface::class);
        $fine->method('getId')->willReturn(8);
        $fine->method('getNumber')->willReturn('000008');
        $fine->method('getItems')->willReturn(new ArrayCollection());
        $fine->method('getPayments')->willReturn(new ArrayCollection());
        $fine->method('getShipments')->willReturn(new ArrayCollection());

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn([]);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([$broken, $fine]);
        $unitOfWork->method('getEntityChangeSet')->willReturn(['state' => ['new', 'cancelled']]);

        $logger = new class() extends AbstractLogger {
            /** @var list<array{string, string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [is_string($level) ? $level : '', (string) $message, $context];
            }
        };
        $urls = new AdminUrlGenerator($this->createMock(UrlGeneratorInterface::class));
        $detector = new OrderEventDetector(new OrderPayloadNormalizer($urls, new CustomerPayloadNormalizer($urls)), new EntityGuard($logger));

        $events = [...$detector->detect($this->createMock(EntityManagerInterface::class), $unitOfWork)];

        self::assertCount(1, $events);
        self::assertSame('order.cancelled', $events[0]->type);
        self::assertSame('000008', $events[0]->data['number']);
        self::assertCount(1, $logger->records);
        self::assertSame('critical', $logger->records[0][0]);
        self::assertSame(7, $logger->records[0][2]['id'], 'The failing entity is identified.');
    }
}
