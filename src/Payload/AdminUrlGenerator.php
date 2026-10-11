<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Payload;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\LazyValue;

/**
 * Absolute admin URL of a resource ("admin_url" of the payloads). In console commands and
 * workers, the host comes from framework.router.default_uri.
 */
final class AdminUrlGenerator
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * @param array<string, mixed> $parameters LazyValue allowed (ids of new entities)
     */
    public function generate(string $route, array $parameters): string|LazyValue|null
    {
        $hasLazy = [] !== array_filter($parameters, static fn (mixed $value): bool => $value instanceof LazyValue);
        if (!$hasLazy) {
            return $this->tryGenerate($route, $parameters);
        }

        return new LazyValue(fn (): ?string => $this->tryGenerate($route, LazyValue::resolveAll($parameters)));
    }

    /** @param array<array-key, mixed> $parameters */
    private function tryGenerate(string $route, array $parameters): ?string
    {
        foreach ($parameters as $value) {
            if (null === $value) {
                return null;
            }
        }

        try {
            /** @var array<string, mixed> $parameters */
            return $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return null;
        }
    }
}
