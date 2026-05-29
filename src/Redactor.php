<?php

declare(strict_types=1);

namespace Xakki\LaraLog;

/**
 * Credential / secret redaction at the process boundary (spec §2 / §2.1).
 *
 * Two modes:
 *  - byKey:   for keyed data (context fields) — match the FIELD NAME against a needle list.
 *  - byValue: for positional data (stack-trace arguments, where no name is available) —
 *             match the VALUE against sensitive patterns (Bearer/JWT/secret-ish).
 *
 * Built-in needles are ALWAYS on; `config('logger.redact', [])` is merged on top.
 */
final class Redactor
{
    public const MASK = '***';

    /**
     * High-signal needles only — substring match, so overly-broad tokens (`auth`, `card`,
     * `pin`, `session`) are deliberately excluded to avoid masking legit fields like
     * `cardinality` / `shipping` / `author`. Add narrow project needles via logger.redact.
     *
     * @var string[]
     */
    private const BUILTIN = [
        'password', 'passwd', 'secret', 'token', 'authorization',
        'api_key', 'apikey', 'access_key', 'private_key', 'credential',
        'cookie', 'cvv', 'card_number', 'cardnumber',
    ];

    /** @var string[]|null */
    private static ?array $needles = null;

    /** @return string[] */
    private static function needles(): array
    {
        if (self::$needles === null) {
            $extra = (array) config('logger.redact', []);
            $extra = array_map(static fn ($v): string => mb_strtolower((string) $v), $extra);
            self::$needles = array_values(array_unique(array_merge(self::BUILTIN, $extra)));
        }
        return self::$needles;
    }

    /** Test seam: drop the cached needle list. */
    public static function flush(): void
    {
        self::$needles = null;
    }

    public static function shouldRedactKey(string $key): bool
    {
        $key = mb_strtolower($key);
        foreach (self::needles() as $needle) {
            if ($needle !== '' && str_contains($key, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Best-effort value redaction for positional values (no key). Masks the whole value
     * when it looks like a bearer token, a JWT, or a `secret=...`/`token: ...` assignment.
     */
    public static function redactValue(string $value): string
    {
        // Bearer / Basic auth header values.
        if (preg_match('/\b(bearer|basic)\s+[A-Za-z0-9._\-\/+=]{8,}/i', $value)) {
            return self::MASK;
        }
        // JWT (three base64url segments).
        if (preg_match('/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', $value)) {
            return self::MASK;
        }
        // key=value / key: value where key is sensitive.
        $needles = implode('|', array_map('preg_quote', self::needles()));
        if ($needles !== '' && preg_match('/(' . $needles . ')\s*[=:]\s*\S+/i', $value)) {
            return (string) preg_replace('/((?:' . $needles . ')\s*[=:]\s*)\S+/i', '$1' . self::MASK, $value);
        }
        return $value;
    }
}
