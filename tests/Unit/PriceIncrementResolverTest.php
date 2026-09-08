<?php

namespace Tests\Unit;

use App\Services\Currency\CurrencyConversionService;
use App\Services\Xs2\PriceIncrementResolver;
use Tests\TestCase;

class PriceIncrementResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('currency.enabled', true);
        config()->set('currency.rates.EUR.GBP', 81.67 / 95);
    }

    public function test_derives_gbp_increment_from_eur_base_value(): void
    {
        $resolver = app(PriceIncrementResolver::class);
        $settings = [
            'default_price_increment_type' => 'fixed',
            'default_price_increment_value' => 2.85,
            'price_increment_by_currency' => [],
        ];

        $byCurrency = $resolver->incrementsByCurrency($settings);

        $this->assertSame(2.85, $byCurrency['EUR']);
        $this->assertSame(
            app(CurrencyConversionService::class)->convertMajor(2.85, 'EUR', 'GBP'),
            $byCurrency['GBP']
        );
    }

    public function test_explicit_currency_overrides_take_precedence(): void
    {
        $resolver = app(PriceIncrementResolver::class);
        $settings = [
            'default_price_increment_type' => 'fixed',
            'default_price_increment_value' => 2.85,
            'price_increment_by_currency' => ['GBP' => 2.45],
        ];

        $byCurrency = $resolver->incrementsByCurrency($settings);

        $this->assertSame(2.85, $byCurrency['EUR']);
        $this->assertSame(2.45, $byCurrency['GBP']);
    }

    public function test_percentage_type_returns_empty_currency_map(): void
    {
        $resolver = app(PriceIncrementResolver::class);
        $settings = [
            'default_price_increment_type' => 'percentage',
            'default_price_increment_value' => 10,
        ];

        $this->assertSame([], $resolver->incrementsByCurrency($settings));
    }
}
