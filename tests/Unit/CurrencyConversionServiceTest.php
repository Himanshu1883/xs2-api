<?php

namespace Tests\Unit;

use App\Exceptions\Integrations\ListingTransformationException;
use App\Models\EventMapping;
use App\Models\MatchInfo;
use App\Services\Currency\CurrencyConversionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CurrencyConversionServiceTest extends TestCase
{
    private CurrencyConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('currency.enabled', true);
        config()->set('currency.rates.EUR.GBP', 81.67 / 95);
        config()->set('services.xs2.minor_unit_divisor', 100);

        $this->service = new CurrencyConversionService();
    }

    public function test_converts_95_eur_to_81_67_gbp_in_major_units(): void
    {
        $this->assertSame(81.67, $this->service->convertMajor(95.0, 'EUR', 'GBP'));
    }

    public function test_converts_95_eur_to_81_67_gbp_in_minor_units(): void
    {
        $this->assertSame(8167, $this->service->convertMinorUnits(9500, 'EUR', 'GBP'));
    }

    public function test_same_currency_is_a_no_op(): void
    {
        $this->assertSame(9500, $this->service->convertMinorUnits(9500, 'EUR', 'EUR'));
        $this->assertSame(95.0, $this->service->convertMajor(95.0, 'EUR', 'EUR'));
    }

    public function test_resolves_event_currency_from_match_info_price_type(): void
    {
        $mapping = new EventMapping(['m_id' => 123]);
        $mapping->setRelation('event', new MatchInfo(['price_type' => 'GBP']));

        $this->assertSame('GBP', $this->service->eventCurrency($mapping));
    }

    public function test_resolves_event_currency_from_match_info_when_event_relation_loaded_as_null(): void
    {
        Schema::create('match_info', function (Blueprint $table): void {
            $table->increments('m_id');
            $table->string('price_type')->nullable();
        });
        DB::table('match_info')->insert(['m_id' => 456, 'price_type' => 'GBP']);

        $mapping = new EventMapping(['m_id' => 456]);
        $mapping->setRelation('event', null);

        $this->assertSame('GBP', $this->service->eventCurrency($mapping));

        Schema::dropIfExists('match_info');
    }

    public function test_throws_when_rate_is_missing(): void
    {
        $this->expectException(ListingTransformationException::class);
        $this->expectExceptionMessage('No currency conversion rate configured from JPY to GBP');

        $this->service->convertMajor(100.0, 'JPY', 'GBP');
    }

    public function test_conversion_summary_for_admin_preview(): void
    {
        $summary = $this->service->conversionSummary(95.0, 'EUR', 'GBP');

        $this->assertNotNull($summary);
        $this->assertTrue($summary['converted']);
        $this->assertSame('EUR', $summary['from_currency']);
        $this->assertSame('GBP', $summary['to_currency']);
        $this->assertSame(95.0, $summary['original_amount_major']);
        $this->assertSame(81.67, $summary['converted_amount_major']);
    }
}
