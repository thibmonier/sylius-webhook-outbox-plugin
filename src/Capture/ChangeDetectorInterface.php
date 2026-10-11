<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Capture;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\WebhookEvent;

/**
 * Derives webhook events from the unit of work during onFlush. Autoconfigured with the
 * "tmonier_webhook.change_detector" tag.
 *
 * Read the scheduled insertions / updates and their change sets only: never flush, persist or
 * query in a way that changes entities. Ids of inserted entities are not known yet: use
 * LazyValue::idOf() for them.
 */
interface ChangeDetectorInterface
{
    /** @return iterable<WebhookEvent> */
    public function detect(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork): iterable;
}
