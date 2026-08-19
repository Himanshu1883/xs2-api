<?php

namespace App\Services\Xs2;

use App\Services\Admin\IntegrationSettingService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ListingPublishRuleSettingService
{
    public const SETTING_KEY = 'LISTING_PUBLISH_RULES';

    public function __construct(
        private readonly IntegrationSettingService $integrationSettings,
    ) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $stored = $this->readStored();
        if ($stored !== null) {
            return $this->normalise($stored);
        }

        return $this->defaults();
    }

    /** @param  array<string, mixed>  $payload */
    public function save(array $payload): array
    {
        $normalised = $this->normalise($payload);
        $this->validate($normalised);

        if (! Schema::hasTable('integration_settings')) {
            throw new \RuntimeException('integration_settings table is not available.');
        }

        $this->integrationSettings->set(
            self::SETTING_KEY,
            json_encode($normalised, JSON_THROW_ON_ERROR),
            secret: false,
        );

        return $normalised;
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return $this->normalise(config('listing_publish_rules', []));
    }

    public function isOverridden(): bool
    {
        return $this->integrationSettings->hasOverride(self::SETTING_KEY);
    }

    /** @return array<string, mixed>|null */
    private function readStored(): ?array
    {
        $raw = $this->integrationSettings->value(self::SETTING_KEY);
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /** @param  array<string, mixed>  $payload */
    private function normalise(array $payload): array
    {
        $defaults = config('listing_publish_rules', []);
        $rules = collect($payload['rules'] ?? $defaults['rules'] ?? [])
            ->filter(fn ($rule): bool => is_array($rule))
            ->values()
            ->map(function (array $rule): array {
                $conditions = collect($rule['conditions'] ?? [])
                    ->filter(fn ($condition): bool => is_array($condition))
                    ->values()
                    ->map(function (array $condition): array {
                        $operator = (string) ($condition['operator'] ?? 'gte');
                        $normalised = [
                            'field' => (string) ($condition['field'] ?? 'stock'),
                            'operator' => $operator,
                        ];

                        if ($operator === 'between') {
                            $normalised['min'] = max(0, (int) ($condition['min'] ?? 0));
                            $normalised['max'] = max($normalised['min'], (int) ($condition['max'] ?? $normalised['min']));
                        } else {
                            $normalised['value'] = max(0, (int) ($condition['value'] ?? 0));
                        }

                        return $normalised;
                    })
                    ->all();

                $action = is_array($rule['action'] ?? null) ? $rule['action'] : [];
                $mode = (string) ($action['mode'] ?? 'single');

                return [
                    'id' => (string) ($rule['id'] ?? ''),
                    'label' => (string) ($rule['label'] ?? ''),
                    'enabled' => (bool) ($rule['enabled'] ?? true),
                    'priority' => max(0, (int) ($rule['priority'] ?? 0)),
                    'conditions' => $conditions,
                    'action' => [
                        'mode' => in_array($mode, ['single', 'split'], true) ? $mode : 'single',
                        'listing_quantity' => max(1, (int) ($action['listing_quantity'] ?? 2)),
                        'listing_quantity_cap_to_stock' => (bool) ($action['listing_quantity_cap_to_stock'] ?? true),
                        'split_size' => max(1, (int) ($action['split_size'] ?? 2)),
                        'pairs_only' => (bool) ($action['pairs_only'] ?? false),
                    ],
                ];
            })
            ->sortBy('priority')
            ->values()
            ->all();

        $incrementType = (string) ($payload['default_price_increment_type'] ?? $defaults['default_price_increment_type'] ?? 'percentage');

        return [
            'enabled' => (bool) ($payload['enabled'] ?? $defaults['enabled'] ?? true),
            'default_price_increment_type' => in_array($incrementType, ['percentage', 'fixed'], true)
                ? $incrementType
                : 'percentage',
            'default_price_increment_value' => max(0, (float) ($payload['default_price_increment_value'] ?? $defaults['default_price_increment_value'] ?? 0)),
            'rules' => $rules,
        ];
    }

    /** @param  array<string, mixed>  $settings */
    private function validate(array $settings): void
    {
        $errors = [];

        if (($settings['rules'] ?? []) === []) {
            $errors['rules'] = ['At least one publish rule is required.'];
        }

        foreach ($settings['rules'] ?? [] as $index => $rule) {
            $prefix = "rules.{$index}";
            if (($rule['id'] ?? '') === '') {
                $errors["{$prefix}.id"] = ['Rule id is required.'];
            }
            if (($rule['label'] ?? '') === '') {
                $errors["{$prefix}.label"] = ['Rule label is required.'];
            }
            if (($rule['conditions'] ?? []) === []) {
                $errors["{$prefix}.conditions"] = ['Each rule needs at least one condition.'];
            }

            $mode = $rule['action']['mode'] ?? 'single';
            if ($mode === 'split' && (int) ($rule['action']['split_size'] ?? 0) < 1) {
                $errors["{$prefix}.action.split_size"] = ['Split size must be at least 1.'];
            }
            if ($mode === 'single' && (int) ($rule['action']['listing_quantity'] ?? 0) < 1) {
                $errors["{$prefix}.action.listing_quantity"] = ['Listing quantity must be at least 1.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
