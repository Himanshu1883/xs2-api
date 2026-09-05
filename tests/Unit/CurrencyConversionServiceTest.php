<?php

namespace Tests\Unit;

use App\Exceptions\Integrations\ListingTransformationException;
use App\Models\EventMapping;
use App\Models\MatchInfo;
use App\Services\Currency\CurrencyConversionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_resolves_event_currency_from_ticket_dropdown_when_match_info_price_type_is_wrong(): void
    {
        Cache::flush();

        config()->set('services.seller_api.enabled', true);
        config()->set('services.seller_api.base_url', 'https://seller.test');
        config()->set('services.seller_api.listing_base_url', 'https://seller.test');
        config()->set('services.seller_api.api_key', 'seller-test-key');
        config()->set('services.seller_api.api_key_header', 'apiKey');
        config()->set('services.seller_api.ticket_dropdown_endpoint', '/api/ticket_dropdown');
        config()->set('services.seller_api.seller_id', 77);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'ticket_dropdown')) {
                return Http::response([
                    'result' => [
                        'currency' => [['currency_code' => 'GBP']],
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $mapping = new EventMapping(['m_id' => 5616]);
        $mapping->setRelation('event', new MatchInfo(['price_type' => 'EUR']));

        $this->assertSame('GBP', $this->service->eventCurrency($mapping, 'EUR'));
    }

    public function test_currency_codes_from_ticket_dropdown_response(): void
    {
        $codes = $this->service->currencyCodesFromTicketDropdown([
            'result' => [
                'currency' => [
                    ['currency_code' => 'GBP'],
                ],
            ],
        ]);

        $this->assertSame(['GBP'], $codes);
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

    public function test_converts_qar_to_usd_using_pegged_rate(): void
    {
        config()->set('currency.rates.QAR.USD', 1 / 3.64);

        $this->assertSame(100.0, $this->service->convertMajor(364.0, 'QAR', 'USD'));
        $this->assertSame(10000, $this->service->convertMinorUnits(36400, 'QAR', 'USD'));
    }

    public function test_converts_qar_to_usd_via_config_defaults(): void
    {
        config()->set('currency.rates', config('currency.rates'));

        $this->assertSame(100.0, $this->service->convertMajor(364.0, 'QAR', 'USD'));
    }

    public function test_resolves_usd_event_currency_from_ticket_dropdown_for_qar_ticket(): void
    {
        Cache::flush();

        config()->set('services.seller_api.enabled', true);
        config()->set('services.seller_api.base_url', 'https://seller.test');
        config()->set('services.seller_api.listing_base_url', 'https://seller.test');
        config()->set('services.seller_api.api_key', 'seller-test-key');
        config()->set('services.seller_api.api_key_header', 'apiKey');
        config()->set('services.seller_api.ticket_dropdown_endpoint', '/api/ticket_dropdown');
        config()->set('services.seller_api.seller_id', 77);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'ticket_dropdown')) {
                return Http::response([
                    'result' => [
                        'currency' => [['currency_code' => 'USD']],
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $mapping = new EventMapping(['m_id' => 9001]);
        $mapping->setRelation('event', new MatchInfo(['price_type' => 'QAR']));

        $this->assertSame('USD', $this->service->eventCurrency($mapping, 'QAR'));
    }
}
