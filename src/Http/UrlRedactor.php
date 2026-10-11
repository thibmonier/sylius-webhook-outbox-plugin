<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Http;

/**
 * Endpoint URLs often carry credentials: user info, tokens in the query string, or the path
 * itself (Slack, Discord, Make, Zapier hooks). Logs, error messages and the admin panel only
 * show the scheme, the host, the port and the path segments that do not look like tokens.
 *
 * URLs are parsed and rebuilt, never string-replaced: messages of the HTTP client quote them
 * re-encoded.
 */
final class UrlRedactor
{
    public const REDACTED = '[redacted]';

    /**
     * Path segments hidden by default: 6+ characters mixing letters and digits (Slack, Zapier
     * hook ids), 5+ digits (account ids), or 20+ token characters (Make, Discord). Short
     * letters-only secrets cannot be told from words: keep secrets out of URLs when possible.
     */
    public const DEFAULT_SECRET_SEGMENT_PATTERN = '/^(?=[^\/]*\d)(?=[^\/]*[A-Za-z])[A-Za-z0-9_\-.~]{6,}$|^\d{5,}$|^[A-Za-z0-9_\-.~]{20,}$/';

    /** Quotes and spaces end a URL; apostrophes do not (they are valid in a query). */
    /**
     * Hook providers whose path is the credential: every segment after the prefix is hidden,
     * whatever it looks like (Zapier hook ids can be letters only).
     */
    public const KNOWN_HOOK_PATHS = [
        '~^hooks\.zapier\.com$~i' => '/hooks/catch/',
        '~^hooks\.slack\.com$~i' => '/services/',
        '~^(?:[a-z0-9-]+\.)?discord(?:app)?\.com$~i' => '/api/webhooks/',
        '~^hook\.(?:[a-z0-9-]+\.)?(?:make|integromat)\.com$~i' => '/',
    ];

    private static ?self $default = null;

    private const URL_IN_TEXT = '~[a-z][a-z0-9+.\-]*://[^\s"<>`]+~i';

    /** @param string|null $secretSegmentPattern null keeps every path segment */
    public function __construct(private readonly ?string $secretSegmentPattern = self::DEFAULT_SECRET_SEGMENT_PATTERN)
    {
    }

    /** The redactor configured by the bundle (entities use it in __debugInfo()). */
    public static function default(): self
    {
        return self::$default ??= new self();
    }

    public static function setDefault(self $redactor): void
    {
        self::$default = $redactor;
    }

    public function redact(string $url): string
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host'])) {
            return '[invalid url]';
        }

        $path = '';
        if (isset($parts['path']) && '' !== $parts['path']) {
            $prefix = self::knownHookPrefix($parts['host'], $parts['path']);
            if (null !== $prefix) {
                $rest = substr($parts['path'], strlen($prefix));
                $path = $prefix . implode('/', array_map(static fn (string $segment): string => '' === $segment ? '' : self::REDACTED, explode('/', $rest)));
            } else {
                $segments = array_map(
                    fn (string $segment): string => null !== $this->secretSegmentPattern && 1 === preg_match($this->secretSegmentPattern, $segment) ? self::REDACTED : $segment,
                    explode('/', $parts['path']),
                );
                $path = implode('/', $segments);
            }
        }

        return sprintf(
            '%s://%s%s%s%s',
            strtolower($parts['scheme'] ?? 'https'),
            $parts['host'],
            isset($parts['port']) ? ':' . $parts['port'] : '',
            $path,
            isset($parts['query']) ? '?' . self::REDACTED : '',
        );
    }

    private static function knownHookPrefix(string $host, string $path): ?string
    {
        foreach (self::KNOWN_HOOK_PATHS as $hostPattern => $prefix) {
            if (1 === preg_match($hostPattern, $host) && 0 === stripos($path, $prefix)) {
                return substr($path, 0, strlen($prefix));
            }
        }

        return null;
    }

    /** Redacts every URL quoted in a message (the endpoint URL in any encoding included). */
    public function redactMessage(string $message, string $url = ''): string
    {
        // The endpoint URL first, in any encoding: whatever follows its scheme and host up to a
        // quote, a space or the end belongs to it.
        $parts = '' === $url ? false : parse_url($url);
        if (false !== $parts && isset($parts['host'])) {
            $origin = '~[a-z][a-z0-9+.\-]*://(?:[^\s"<>`/@]*@)?' . preg_quote($parts['host'], '~') . '(?::\d+)?(?:[/?#][^\s"<>`]*)?~i';
            $message = preg_replace($origin, $this->redact($url), $message) ?? '';
        }

        $redacted = preg_replace_callback(
            self::URL_IN_TEXT,
            fn (array $match): string => $this->redact(rtrim($match[0], '.,;:)')) . substr($match[0], strlen(rtrim($match[0], '.,;:)'))),
            $message,
        ) ?? '';

        // Credentials of the endpoint quoted without their URL (rare, but cheap to remove).
        if (false !== $parts) {
            foreach ([$parts['user'] ?? null, $parts['pass'] ?? null, $parts['query'] ?? null] as $secret) {
                if (null !== $secret && strlen($secret) >= 4) {
                    $redacted = str_replace([$secret, rawurlencode($secret), rawurldecode($secret)], self::REDACTED, $redacted);
                }
            }
        }

        return mb_substr($redacted, 0, 1000);
    }
}
