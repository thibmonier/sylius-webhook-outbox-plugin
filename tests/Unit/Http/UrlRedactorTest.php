<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\Http;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusWebhookOutboxPlugin\Http\UrlRedactor;

final class UrlRedactorTest extends TestCase
{
    public function testQueryUserInfoAndTokenSegmentsAreHidden(): void
    {
        $redactor = new UrlRedactor();

        self::assertSame('https://erp.example.com:8443/hook?[redacted]', $redactor->redact('https://u:p@erp.example.com:8443/hook?token=abc'));
        self::assertSame(
            'https://hooks.slack.com/services/[redacted]/[redacted]/[redacted]',
            $redactor->redact('https://hooks.slack.com/services/' . 'T0123ABCD/B0456EFGH/' . 'aBcDeFgHiJkLmNoPqRsTuVwX'),
        );
        self::assertSame('https://hook.eu1.make.com/[redacted]', $redactor->redact('https://hook.eu1.make.com/zq7xfd4mw2c9bnl1rk5t8yhv3e6a0gpj'));
        self::assertSame('https://erp.example.com/webhooks/sylius/v2', $redactor->redact('https://erp.example.com/webhooks/sylius/v2'));
        self::assertSame('https://hooks.zapier.com/hooks/catch/[redacted]/[redacted]/', $redactor->redact('https://hooks.zapier.com/hooks/catch/123456/abc1de2/'));
        self::assertSame('https://discord.com/api/webhooks/[redacted]/[redacted]', $redactor->redact('https://discord.com/api/webhooks/112233445566778899/AbCdEfGhIjKlMnOpQrStUvWxYz_0123456789'));
        self::assertSame('[invalid url]', $redactor->redact('nope'));
    }

    public function testPathRedactionCanBeDisabled(): void
    {
        self::assertSame('https://hook.example.com/zq7xfd4mw2c9bnl1?[redacted]', (new UrlRedactor(null))->redact('https://hook.example.com/zq7xfd4mw2c9bnl1?a=b'));
    }

    public function testApostrophesInTheQueryDoNotLeakTheRestOfTheToken(): void
    {
        $redactor = new UrlRedactor();
        $url = "https://hook.example.com/x?sig=ab'cdTOKEN";

        $message = $redactor->redactMessage('Idle timeout reached for "https://hook.example.com/x?sig=ab\'cdTOKEN".', $url);
        self::assertSame('Idle timeout reached for "https://hook.example.com/x?[redacted]".', $message);
        self::assertStringNotContainsString('TOKEN', $redactor->redactMessage("Failed: https://other.example.net/y?k=a'bTOKEN end", $url));
    }

    public function testMessagesAreRedactedWhateverTheEncoding(): void
    {
        $redactor = new UrlRedactor();
        $url = 'https://erp.example.com/hook?sig=a|b c';

        $message = $redactor->redactMessage('Idle timeout reached for "https://erp.example.com/hook?sig=a%7Cb%20c".', $url);
        self::assertSame('Idle timeout reached for "https://erp.example.com/hook?[redacted]".', $message);

        $message = $redactor->redactMessage('Could not resolve host: https://user:secret@hooks.example.com/T0123ABCD9/x, giving up', 'https://user:secret@hooks.example.com/T0123ABCD9/x');
        self::assertStringNotContainsString('secret', $message);
        self::assertStringNotContainsString('T0123ABCD9', $message);
        self::assertStringContainsString('hooks.example.com', $message);
    }
}
