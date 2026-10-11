<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpoint;

/**
 * dump(), the profiler and the debug toolbar go through VarDumper, which ignores
 * __debugInfo(): the bundle registers a caster.
 */
final class EndpointDumpTest extends KernelTestCase
{
    public function testDumpsNeverShowTheSecretNorTheUrlCredentials(): void
    {
        self::bootKernel();
        $endpoint = new WebhookEndpoint();
        $endpoint->setName('Zapier');
        $endpoint->setUrl('https://hooks.zapier.com/hooks/catch/abcdef/ghijkl/?token=s3cr3t');
        $endpoint->setSecret('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw');

        $dumper = new CliDumper();
        $output = (string) $dumper->dump((new VarCloner())->cloneVar(['endpoint' => $endpoint]), true);
        $debugInfo = print_r($endpoint, true);

        foreach ([$output, $debugInfo] as $dump) {
            self::assertStringContainsString('hooks.zapier.com/hooks/catch/', $dump);
            foreach (['MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', 'abcdef', 'ghijkl', 's3cr3t'] as $secret) {
                self::assertStringNotContainsString($secret, $dump);
            }
        }
    }
}
