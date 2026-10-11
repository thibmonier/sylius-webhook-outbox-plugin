<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\Signature;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusWebhookOutboxPlugin\Signature\SecretGenerator;
use Tmonier\SyliusWebhookOutboxPlugin\Signature\WebhookSigner;

final class WebhookSignerTest extends TestCase
{
    /** Test vector of the Standard Webhooks specification and reference libraries. */
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    private const ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';

    private const TIMESTAMP = 1614265330;

    private const BODY = '{"test": 2432232314}';

    private const SIGNATURE = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

    public function testItMatchesTheSpecificationVector(): void
    {
        self::assertSame(self::SIGNATURE, (new WebhookSigner())->sign(self::ID, self::TIMESTAMP, self::BODY, self::SECRET));
    }

    public function testSecretWithoutPrefixGivesTheSameSignature(): void
    {
        self::assertSame(self::SIGNATURE, (new WebhookSigner())->sign(self::ID, self::TIMESTAMP, self::BODY, 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'));
    }

    public function testHeaders(): void
    {
        self::assertSame([
            'webhook-id' => self::ID,
            'webhook-timestamp' => '1614265330',
            'webhook-signature' => self::SIGNATURE,
        ], (new WebhookSigner())->headers(self::ID, self::TIMESTAMP, self::BODY, self::SECRET));
    }

    public function testVerify(): void
    {
        $signer = new WebhookSigner();

        self::assertTrue($signer->verify(self::ID, '1614265330', self::BODY, self::SIGNATURE, self::SECRET, self::TIMESTAMP + 10));
        self::assertTrue($signer->verify(self::ID, '1614265330', self::BODY, 'v1,b2xk ' . self::SIGNATURE, self::SECRET, self::TIMESTAMP), 'Several signatures (secret rotation).');
        self::assertFalse($signer->verify(self::ID, '1614265330', self::BODY . ' ', self::SIGNATURE, self::SECRET, self::TIMESTAMP), 'Tampered body.');
        self::assertFalse($signer->verify('msg_other', '1614265330', self::BODY, self::SIGNATURE, self::SECRET, self::TIMESTAMP), 'Other id.');
        self::assertFalse($signer->verify(self::ID, '1614265330', self::BODY, self::SIGNATURE, self::SECRET, self::TIMESTAMP + 301), 'Too old.');
        self::assertFalse($signer->verify(self::ID, '1614265330', self::BODY, self::SIGNATURE, self::SECRET, self::TIMESTAMP - 301), 'Too new.');
        self::assertFalse($signer->verify(self::ID, 'abc', self::BODY, self::SIGNATURE, self::SECRET, self::TIMESTAMP));
    }

    public function testInvalidSecretErrorDoesNotLeakIt(): void
    {
        try {
            (new WebhookSigner())->sign(self::ID, self::TIMESTAMP, self::BODY, 'whsec_not base64 !');
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringNotContainsString('not base64', $exception->getMessage());
        }
    }

    public function testGeneratedSecretsAreStandardAndUnique(): void
    {
        $generator = new SecretGenerator();
        $secret = $generator->generate();

        self::assertMatchesRegularExpression('/^whsec_[A-Za-z0-9+\/]{43}=$/', $secret);
        self::assertSame(32, strlen(WebhookSigner::secretBytes($secret)));
        self::assertNotSame($secret, $generator->generate());
    }
}
