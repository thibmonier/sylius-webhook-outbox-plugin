<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\CoreEventTypeProvider;

final class TranslationsTest extends TestCase
{
    private const LOCALES = ['en', 'fr', 'de', 'es', 'it', 'nl', 'pl'];

    private const DOMAINS = ['messages', 'flashes', 'validators'];

    /** @return iterable<string, array{string, string}> */
    public static function catalogues(): iterable
    {
        foreach (self::DOMAINS as $domain) {
            foreach (self::LOCALES as $locale) {
                yield $domain . '.' . $locale => [$domain, $locale];
            }
        }
    }

    #[DataProvider('catalogues')]
    public function testEveryLocaleDefinesTheSameKeys(string $domain, string $locale): void
    {
        $reference = $this->flatten($this->parse($domain, 'en'));
        $catalogue = $this->flatten($this->parse($domain, $locale));

        self::assertSame([], array_values(array_diff(array_keys($reference), array_keys($catalogue))), 'Missing keys');
        self::assertSame([], array_values(array_diff(array_keys($catalogue), array_keys($reference))), 'Unknown keys');
        foreach ($catalogue as $key => $value) {
            self::assertNotSame('', trim($value), sprintf('Empty translation "%s"', $key));
            preg_match_all('/%\w+%/', $reference[$key], $expected);
            preg_match_all('/%\w+%/', $value, $actual);
            self::assertEqualsCanonicalizing($expected[0], $actual[0], sprintf('Placeholders of "%s"', $key));
        }
    }

    public function testEveryCoreEventTypeAndStatusHasALabel(): void
    {
        $messages = $this->flatten($this->parse('messages', 'en'));

        foreach ((new CoreEventTypeProvider())->getEventTypes() as $type) {
            self::assertArrayHasKey($type->getLabel(), $messages);
        }
        foreach (WebhookDeliveryInterface::STATUSES as $status) {
            self::assertArrayHasKey('tmonier_webhook.ui.status.' . $status, $messages);
        }
    }

    public function testEveryUrlViolationHasAMessage(): void
    {
        $validators = $this->flatten($this->parse('validators', 'en'));

        foreach (['invalid', 'https_only', 'http_or_https', 'no_credentials'] as $violation) {
            self::assertArrayHasKey('tmonier_webhook.endpoint.url.' . $violation, $validators);
        }
    }

    /** @return array<mixed> */
    private function parse(string $domain, string $locale): array
    {
        $data = Yaml::parseFile(sprintf('%s/../../translations/%s.%s.yaml', __DIR__, $domain, $locale));
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix . $key . '.');

                continue;
            }

            $flat[$prefix . $key] = is_scalar($value) ? (string) $value : '';
        }

        return $flat;
    }
}
