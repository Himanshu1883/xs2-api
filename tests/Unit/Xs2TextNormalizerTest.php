<?php

namespace Tests\Unit;

use App\Services\Xs2\Xs2TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Xs2TextNormalizerTest extends TestCase
{
    private Xs2TextNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = app(Xs2TextNormalizer::class);
    }

    #[DataProvider('normalizationExamples')]
    public function test_normalize_strips_accents_filler_words_and_club_suffixes(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($input));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function normalizationExamples(): array
    {
        return [
            'celta fixture with accents and de' => [
                'Celta de Vigo vs Atlético Madrid',
                'celta vigo vs atletico madrid',
            ],
            'seats broker variant without de' => [
                'Celta Vigo vs Atletico Madrid',
                'celta vigo vs atletico madrid',
            ],
            'team name with cf suffix' => [
                'Valencia CF',
                'valencia',
            ],
            'team name with football club suffix' => [
                'Alpha Football Club',
                'alpha',
            ],
            'versus separators' => [
                'Alpha FC v Beta FC',
                'alpha vs beta',
            ],
            'mixed case versus separator' => [
                'Atalanta Vs Parma',
                'atalanta vs parma',
            ],
            'doubled leading letter typo' => [
                'JJuventus FC vs Fiorentina',
                'juventus vs fiorentina',
            ],
            'juventus fc suffix removal' => [
                'Juventus FC',
                'juventus',
            ],
            'ss lazio prefix preserved' => [
                'SS Lazio',
                'ss lazio',
            ],
        ];
    }

    #[DataProvider('similarityExamples')]
    public function test_similarity_scores_near_identical_fixtures_at_one_hundred(string $first, string $second): void
    {
        $this->assertSame(100.0, $this->normalizer->similarity($first, $second));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function similarityExamples(): array
    {
        return [
            'celta vs atletico event titles' => [
                'Celta de Vigo vs Atlético Madrid',
                'Celta Vigo vs Atletico Madrid',
            ],
            'celta home team' => [
                'Celta de Vigo',
                'Celta Vigo',
            ],
            'atletico away team accents' => [
                'Atlético Madrid',
                'Atletico Madrid',
            ],
            'valencia fixture with cf and de' => [
                'Valencia CF vs Celta de Vigo',
                'Valencia Vs Celta Vigo',
            ],
            'jjuventus typo with fc suffix' => [
                'JJuventus FC vs Fiorentina',
                'Juventus vs Fiorentina',
            ],
            'juventus fc vs fiorentina' => [
                'Juventus FC vs Fiorentina',
                'Juventus vs Fiorentina',
            ],
            'atalanta versus case variants' => [
                'Atalanta vs Parma',
                'Atalanta Vs Parma',
            ],
        ];
    }
}
