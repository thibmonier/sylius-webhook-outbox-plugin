<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Entity;

use Sylius\Resource\Model\TimestampableTrait;
use Tmonier\SyliusWebhookOutboxPlugin\Http\UrlRedactor;

class WebhookEndpoint implements WebhookEndpointInterface
{
    use TimestampableTrait;

    protected ?int $id = null;

    protected ?string $name = null;

    protected ?string $url = null;

    protected ?string $description = null;

    protected ?string $secret = null;

    protected bool $enabled = true;

    /** @var list<string> */
    protected array $eventTypes = [];

    /** @var list<string> */
    protected array $channelCodes = [];

    protected ?\DateTimeInterface $disabledAt = null;

    protected ?string $disabledReason = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): void
    {
        $this->url = null === $url ? null : trim($url);
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function setSecret(#[\SensitiveParameter] string $secret): void
    {
        $this->secret = $secret;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        if ($enabled) {
            $this->disabledAt = null;
            $this->disabledReason = null;
        }
    }

    public function getEventTypes(): array
    {
        return $this->eventTypes;
    }

    public function setEventTypes(array $eventTypes): void
    {
        $this->eventTypes = array_values(array_unique($eventTypes));
    }

    public function isSubscribedTo(string $eventType): bool
    {
        return in_array($eventType, $this->eventTypes, true);
    }

    public function getChannelCodes(): array
    {
        return $this->channelCodes;
    }

    public function setChannelCodes(array $channelCodes): void
    {
        $this->channelCodes = array_values(array_unique($channelCodes));
    }

    public function acceptsChannel(?string $channelCode): bool
    {
        return null === $channelCode || [] === $this->channelCodes || in_array($channelCode, $this->channelCodes, true);
    }

    public function getDisabledAt(): ?\DateTimeInterface
    {
        return $this->disabledAt;
    }

    public function getDisabledReason(): ?string
    {
        return $this->disabledReason;
    }

    public function disable(string $reason, \DateTimeImmutable $at): void
    {
        $this->enabled = false;
        $this->disabledAt = \DateTime::createFromImmutable($at);
        $this->disabledReason = mb_substr($reason, 0, 255);
    }

    /**
     * Keeps the secret and the URL credentials out of var_dump(), dump() and serialized
     * exception contexts.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => null === $this->url ? null : UrlRedactor::default()->redact($this->url),
            'enabled' => $this->enabled,
            'secret' => null === $this->secret ? null : '[redacted]',
        ];
    }
}
