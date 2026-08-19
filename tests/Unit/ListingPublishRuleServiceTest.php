<?php

namespace Tests\Unit;

use App\Services\SplitListings\SplitListingService;
use App\Services\Xs2\ListingPublishRuleService;
use App\Services\Xs2\ListingPublishRuleSettingService;
use Mockery;
use Tests\TestCase;

class ListingPublishRuleServiceTest extends TestCase
{
    private ListingPublishRuleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('listing_publish_rules', [
            'enabled' => true,
            'default_price_increment_type' => 'percentage',
            'default_price_increment_value' => 0,
            'rules' => [
                [
                    'id' => 'low_stock',
                    'label' => 'Low stock (1–4 tickets)',
                    'enabled' => true,
                    'priority' => 10,
                    'conditions' => [
                        ['field' => 'stock', 'operator' => 'between', 'min' => 1, 'max' => 4],
                    ],
                    'action' => [
                        'mode' => 'single',
                        'listing_quantity' => 2,
                        'listing_quantity_cap_to_stock' => true,
                        'pairs_only' => true,
                    ],
                ],
                [
                    'id' => 'high_stock',
                    'label' => 'High stock (5+ tickets)',
                    'enabled' => true,
                    'priority' => 20,
                    'conditions' => [
                        ['field' => 'stock', 'operator' => 'gte', 'value' => 5],
                    ],
                    'action' => [
                        'mode' => 'split',
                        'split_size' => 2,
                        'pairs_only' => true,
                    ],
                ],
            ],
        ]);

        $settings = Mockery::mock(ListingPublishRuleSettingService::class);
        $settings->shouldReceive('get')->andReturnUsing(fn (): array => config('listing_publish_rules'));
        $settings->shouldReceive('isOverridden')->andReturn(false);

        $this->service = new ListingPublishRuleService(
            $settings,
            app(SplitListingService::class),
        );
    }

    public function test_qty_four_publishes_one_listing_with_qty_two_pairs_only(): void
    {
        $preview = $this->service->previewForStock(4);

        $this->assertTrue($preview['matched']);
        $this->assertSame('low_stock', $preview['rule_id']);
        $this->assertSame('single', $preview['mode']);
        $this->assertTrue($preview['pairs_only']);
        $this->assertSame(1, $preview['listings_count']);
        $this->assertSame(2, $preview['listings'][0]['quantity']);
    }

    public function test_qty_eight_publishes_four_split_listings_each_qty_two_pairs_only(): void
    {
        $preview = $this->service->previewForStock(8);

        $this->assertTrue($preview['matched']);
        $this->assertSame('high_stock', $preview['rule_id']);
        $this->assertSame('split', $preview['mode']);
        $this->assertTrue($preview['pairs_only']);
        $this->assertSame(4, $preview['listings_count']);
        $this->assertSame([2, 2, 2, 2], array_column($preview['listings'], 'quantity'));
    }

    public function test_qty_three_caps_single_listing_quantity_to_stock(): void
    {
        $preview = $this->service->previewForStock(3);

        $this->assertSame('single', $preview['mode']);
        $this->assertSame(2, $preview['listings'][0]['quantity']);
    }

    public function test_qty_one_caps_single_listing_quantity_to_one(): void
    {
        $preview = $this->service->previewForStock(1);

        $this->assertSame('single', $preview['mode']);
        $this->assertSame(1, $preview['listings'][0]['quantity']);
    }
}
