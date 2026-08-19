<?php

namespace App\Support;

/**
 * Seatsbrokers external catalog hashes legacy integer PKs as MD5 hex strings
 * (event_id = md5(m_id), stadium_id = md5(s_id), tournament_id = md5(t_id), …).
 */
class SeatsbrokerCatalogId
{
    public static function hash(int|string $id): string
    {
        return md5((string) $id);
    }

    /**
     * Reverse an MD5 catalog hash to its integer primary key.
     * Returns null when the hash is not md5 of an integer in 1..$max.
     */
    public static function resolve(mixed $hash, int $max = 250_000): ?int
    {
        if (! is_string($hash) && ! is_numeric($hash)) {
            return null;
        }

        $normalized = strtolower(trim((string) $hash));
        if ($normalized === '') {
            return null;
        }

        if (ctype_digit($normalized)) {
            $id = (int) $normalized;

            return $id > 0 ? $id : null;
        }

        if (! preg_match('/^[a-f0-9]{32}$/', $normalized)) {
            return null;
        }

        for ($id = 1; $id <= $max; $id++) {
            if (md5((string) $id) === $normalized) {
                return $id;
            }
        }

        return null;
    }
}
