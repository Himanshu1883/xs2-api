<?php

namespace App\Support;

/**
 * Provider console copy should stay in English. Legacy catalog rows may store
 * Arabic translations or script; prefer Latin/English labels and skip Arabic
 * script when resolving display names.
 */
final class EnglishDisplayText
{
    public static function resolve(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $normalized = self::preferEnglish($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    public static function preferEnglish(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = trim($value);
        if ($name === '' || ctype_digit($name)) {
            return null;
        }

        if (self::containsArabicScript($name)) {
            return null;
        }

        return $name;
    }

    public static function containsArabicScript(string $value): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $value);
    }

    public static function teamEventTitle(?string $home, ?string $away): ?string
    {
        $home = self::preferEnglish($home);
        $away = self::preferEnglish($away);

        if ($home !== null && $away !== null) {
            return "{$home} vs {$away}";
        }

        return $home ?? $away;
    }
}
