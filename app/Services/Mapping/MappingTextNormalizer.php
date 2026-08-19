<?php

namespace App\Services\Mapping;

use Illuminate\Support\Str;

class MappingTextNormalizer
{
    /** @var array<string, string> */
    private const CITY_ALIASES = [
        'munchen' => 'munich',
        'muenchen' => 'munich',
        'torino' => 'turin',
        'koln' => 'cologne',
    ];

    /** @var list<string> */
    private const HOSPITALITY_KEYWORDS = ['vip', 'hospitality', 'box', 'suite', 'lounge', 'premium', 'club', 'private'];

    public function normalizeCountry(string $value): string
    {
        return $this->normalize($value);
    }

    public function normalizeCity(string $value): string
    {
        $normalized = $this->normalize($value);

        return self::CITY_ALIASES[$normalized] ?? $normalized;
    }

    public function normalizeStadium(string $value): string
    {
        return $this->normalize($value);
    }

    public function normalizeCategory(string $value): string
    {
        return $this->normalize($value);
    }

    /** @return list<string> */
    public function tokens(string $value): array
    {
        $normalized = $this->normalize($value);

        return $normalized === '' ? [] : array_values(array_filter(explode(' ', $normalized)));
    }

    public function hasHospitalityKeyword(string $value): bool
    {
        return array_intersect($this->tokens($value), self::HOSPITALITY_KEYWORDS) !== [];
    }

    /** @return list<string> */
    public function stadiumTokens(string $value): array
    {
        $generic = ['stadium', 'arena', 'ground', 'stade', 'stadio', 'estadio', 'park'];

        return array_values(array_filter(
            $this->tokens($value),
            fn (string $token): bool => ! in_array($token, $generic, true),
        ));
    }

    private function normalize(string $value): string
    {
        $value = Str::ascii($value);
        $value = str_ireplace('&', ' and ', $value);
        $value = str_replace(['-', '_', '/'], ' ', $value);
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '';

        return trim((string) preg_replace('/\s+/', ' ', Str::lower($value)));
    }
}
