<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Entity;

use Sylius\Resource\Model\ResourceInterface;
use Sylius\Resource\Model\TimestampableInterface;

interface WebhookEndpointInterface extends ResourceInterface, TimestampableInterface
{
    public function getId(): ?int;

    public function getName(): ?string;

    public function setName(?string $name): void;

    public function getUrl(): ?string;

    public function setUrl(?string $url): void;

    public function getDescription(): ?string;

    public function setDescription(?string $description): void;

    /**
     * Signing secret ("whsec_" + base64). Shown once in the admin panel, never logged.
     */
    public function getSecret(): ?string;

    public function setSecret(#[\SensitiveParameter] string $secret): void;

    public function isEnabled(): bool;

    public function setEnabled(bool $enabled): void;

    /** @return list<string> */
    public function getEventTypes(): array;

    /** @param list<string> $eventTypes */
    public function setEventTypes(array $eventTypes): void;

    public function isSubscribedTo(string $eventType): bool;

    /**
     * Channel codes whose events are sent; empty means every channel. Events without channel
     * (customers, stock) are always sent.
     *
     * @return list<string>
     */
    public function getChannelCodes(): array;

    /** @param list<string> $channelCodes */
    public function setChannelCodes(array $channelCodes): void;

    public function acceptsChannel(?string $channelCode): bool;

    public function getDisabledAt(): ?\DateTimeInterface;

    public function getDisabledReason(): ?string;

    /** Disables the endpoint automatically (e.g. after "410 Gone"). */
    public function disable(string $reason, \DateTimeImmutable $at): void;
}
