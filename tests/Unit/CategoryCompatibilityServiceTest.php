<?php

namespace Tests\Unit;

use App\Services\Mapping\CategoryCompatibilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoryCompatibilityServiceTest extends TestCase
{
    private CategoryCompatibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CategoryCompatibilityService::class);
    }

    #[DataProvider('compatibleCategoryExamples')]
    public function test_get_compatible_categories_expands_football_and_sports(string $input, array $expected): void
    {
        $this->assertSame($expected, $this->service->getCompatibleCategories($input));
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function compatibleCategoryExamples(): array
    {
        return [
            'football expands to football and sports' => ['Football', ['Football', 'Sports']],
            'sports expands to football and sports' => ['Sports', ['Football', 'Sports']],
            'other categories stay exact' => ['Concerts', ['Concerts']],
        ];
    }

    #[DataProvider('compatibilityMatrix')]
    public function test_is_compatible_category(string $xs2Category, string $localCategory, bool $expected): void
    {
        $this->assertSame($expected, $this->service->isCompatibleCategory($xs2Category, $localCategory));
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function compatibilityMatrix(): array
    {
        return [
            'football to football' => ['Football', 'Football', true],
            'football to sports' => ['Football', 'Sports', true],
            'sports to football' => ['Sports', 'Football', true],
            'sports to sports' => ['Sports', 'Sports', true],
            'football to concerts' => ['Football', 'Concerts', false],
            'concerts to sports' => ['Concerts', 'Sports', false],
        ];
    }

    public function test_resolve_xs2_event_category_maps_soccer_to_football(): void
    {
        $event = new \App\Models\Xs2Event(['sport_type' => 'soccer']);

        $this->assertSame('Football', $this->service->resolveXs2EventCategory($event));
    }
}
