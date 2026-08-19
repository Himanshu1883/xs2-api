<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Admin\IntegrationSettingService;
use App\Services\Xs2\ListingPublishRuleSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminListingPublishRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedUsersTable();
    }

    public function test_admin_can_view_default_listing_publish_rules(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('listing-publish-rules')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/settings/listing-publish-rules')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.rules.0.id', 'low_stock')
            ->assertJsonPath('data.rules.0.action.mode', 'single')
            ->assertJsonPath('data.rules.1.id', 'high_stock')
            ->assertJsonPath('data.rules.1.action.mode', 'split')
            ->assertJsonPath('data.examples.0.stock', 4)
            ->assertJsonPath('data.examples.0.listings_count', 1)
            ->assertJsonPath('data.examples.1.stock', 8)
            ->assertJsonPath('data.examples.1.listings_count', 4);
    }

    public function test_admin_can_update_listing_publish_rules(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('listing-publish-rules-update')->plainTextToken;

        $payload = app(ListingPublishRuleSettingService::class)->get();
        $payload['rules'][0]['action']['listing_quantity'] = 3;

        $this->withToken($token)
            ->patchJson('/api/admin/settings/listing-publish-rules', $payload)
            ->assertOk()
            ->assertJsonPath('data.rules.0.action.listing_quantity', 3);

        $stored = app(IntegrationSettingService::class)->value(IntegrationSettingService::LISTING_PUBLISH_RULES);
        $this->assertNotNull($stored);
        $this->assertStringContainsString('"listing_quantity":3', $stored);
    }

    public function test_admin_can_preview_rules_for_custom_stock(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('listing-publish-rules-preview')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/admin/settings/listing-publish-rules/preview', ['stock' => 8])
            ->assertOk()
            ->assertJsonPath('data.stock', 8)
            ->assertJsonPath('data.mode', 'split')
            ->assertJsonPath('data.listings_count', 4);
    }

    public function test_admin_can_add_a_new_publish_rule(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('listing-publish-rules-add')->plainTextToken;

        $payload = app(ListingPublishRuleSettingService::class)->get();
        $payload['rules'][] = [
            'id' => 'custom_rule_test',
            'label' => 'Medium stock (5–8 tickets)',
            'enabled' => true,
            'priority' => 15,
            'conditions' => [
                [
                    'field' => 'stock',
                    'operator' => 'between',
                    'min' => 5,
                    'max' => 8,
                ],
            ],
            'action' => [
                'mode' => 'single',
                'listing_quantity' => 2,
                'listing_quantity_cap_to_stock' => true,
                'split_size' => 2,
                'pairs_only' => false,
            ],
        ];

        $this->withToken($token)
            ->patchJson('/api/admin/settings/listing-publish-rules', $payload)
            ->assertOk()
            ->assertJsonPath('data.rules.1.id', 'custom_rule_test')
            ->assertJsonPath('data.rules.1.action.mode', 'single')
            ->assertJsonCount(3, 'data.rules');
    }

    public function test_admin_cannot_save_duplicate_rule_ids(): void
    {
        $user = User::factory()->create(['user_type' => 6]);
        $token = $user->createToken('listing-publish-rules-duplicate')->plainTextToken;

        $payload = app(ListingPublishRuleSettingService::class)->get();
        $payload['rules'][] = $payload['rules'][0];

        $this->withToken($token)
            ->patchJson('/api/admin/settings/listing-publish-rules', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rules.1.id']);
    }
}
