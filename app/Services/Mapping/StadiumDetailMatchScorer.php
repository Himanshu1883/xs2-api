<?php

namespace App\Services\Mapping;

use App\DTOs\Xs2\CategoryMatchResult;
use App\Models\Xs2Category;

class StadiumDetailMatchScorer
{
    public function __construct(private readonly MappingTextNormalizer $text) {}

    /** @param array<string,mixed> $detail */
    public function score(Xs2Category $category, array $detail): CategoryMatchResult
    {
        [$requested] = explode('_', (string) $category->category_name, 2);
        $requested = trim($requested);
        $detailName = (string) ($detail['name'] ?? '');
        $requestedNormalized = $this->text->normalizeCategory($requested);
        $detailNormalized = $this->text->normalizeCategory($detailName);
        $matched = [];

        if ($requestedNormalized !== '' && $requestedNormalized === $detailNormalized) {
            $nameScore = 70.0;
            $method = 'exact_name';
            $matched[] = 'exact_detail_name';
        } else {
            similar_text($requestedNormalized, $detailNormalized, $similarity);
            $nameScore = min(70, max(0, $similarity * .7));
            $method = 'fuzzy';
            if ($nameScore > 0) {
                $matched[] = 'similar_detail_name';
            }
        }

        $score = $nameScore;
        $identifier = $this->identifier($requested);
        $block = (string) ($detail['block'] ?? '');
        $section = (string) ($detail['section'] ?? '');
        if ($identifier !== null && ($this->containsIdentifier($block, $identifier) || $this->containsIdentifier($section, $identifier) || $this->containsIdentifier($detailName, $identifier))) {
            $score += 25;
            $matched[] = 'block_or_section';
        }

        $seat = (string) ($detail['stadium_seat_name'] ?? '');
        if ($seat !== '' && $this->tokenOverlap($requested, $seat) > 0) {
            $score += 15;
            $matched[] = 'stadium_seat_name';
        }

        $categoryType = strtolower((string) ($category->context?->category_type ?? ''));
        if (in_array($categoryType, ['hospitality', 'offsite_hospitality'], true)) {
            $candidateText = trim($detailName.' '.$block.' '.$section);
            if ($candidateText !== '' && $this->text->hasHospitalityKeyword($candidateText)) {
                $score += (float) config('xs2.mapping.category_hospitality_keyword_score', 20);
                $matched[] = 'hospitality_keyword';
            }
        }

        return new CategoryMatchResult(
            stadiumDetailId: $detail['stadium_detail_id'] ?? null,
            stadiumSeatId: $detail['stadium_seat_id'] ?? null,
            score: round(min(100, $score), 2),
            method: $method,
            matchedFields: $matched,
            candidate: [
                'name' => $detailName,
                'block' => $detail['block'] ?? null,
                'section' => $detail['section'] ?? null,
                'stadium_seat_name' => $detail['stadium_seat_name'] ?? null,
            ],
        );
    }

    private function identifier(string $value): ?string
    {
        if (preg_match('/\b(?:block|section)\s*([a-z0-9-]+)\b/i', $value, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    private function containsIdentifier(string $value, string $identifier): bool
    {
        return preg_match('/\b'.preg_quote($identifier, '/').'\b/i', $value) === 1;
    }

    private function tokenOverlap(string $left, string $right): int
    {
        return count(array_intersect($this->text->tokens($left), $this->text->tokens($right)));
    }
}
