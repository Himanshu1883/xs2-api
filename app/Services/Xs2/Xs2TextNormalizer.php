<?php

namespace App\Services\Xs2;

use Illuminate\Support\Str;

class Xs2TextNormalizer
{
    /** @var list<string> */
    private const CLUB_SUFFIXES = ['football club', 'afc', 'fc', 'cf', 'sc', 'ac'];

    /** @var list<string> */
    private const FILLER_WORDS = ['de', 'da', 'do', 'dos', 'das', 'del', 'di', 'della', 'the', 'of'];

    public function normalize(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $value = Str::ascii(Str::lower($value));
        $value = preg_replace('/\b('.implode('|', self::CLUB_SUFFIXES).')\b/', ' ', $value) ?? $value;
        $value = preg_replace('/\b('.implode('|', self::FILLER_WORDS).')\b/', ' ', $value) ?? $value;
        $value = preg_replace('/\b(vs|versus|v)\b|[-–—]/', ' vs ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return $this->collapseLeadingDoubledLetters($value);
    }

    private function collapseLeadingDoubledLetters(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $words = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $words = array_map(function (string $word): string {
            if (strlen($word) < 4 || ! ctype_alpha($word[0]) || $word[0] !== $word[1]) {
                return $word;
            }

            return substr($word, 1);
        }, $words);

        return implode(' ', $words);
    }

    public function similarity(?string $first, ?string $second): float
    {
        $first = $this->normalize($first);
        $second = $this->normalize($second);

        if ($first === '' || $second === '') {
            return 0.0;
        }

        if ($first === $second) {
            return 100.0;
        }

        similar_text($first, $second, $percentage);

        return round($percentage, 2);
    }
}
