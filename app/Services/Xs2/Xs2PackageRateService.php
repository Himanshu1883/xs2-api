<?php

namespace App\Services\Xs2;

use Illuminate\Support\Facades\Log;

class Xs2PackageRateService
{
    /** @param  list<string>  $flags */
    public function isPackageRate(array $flags): bool
    {
        return in_array('package_rate', $flags, true);
    }

    /**
     * @return array{
     *     is_package_rate: bool,
     *     package_quantity: int|null,
     *     package_price: int|null
     * }
     */
    public function resolveFromPayload(array $payload): array
    {
        $flags = is_array($payload['flags'] ?? null) ? $payload['flags'] : [];

        if (! $this->isPackageRate($flags)) {
            return [
                'is_package_rate' => false,
                'package_quantity' => null,
                'package_price' => null,
            ];
        }

        $packagePrice = isset($payload['net_rate'])
            ? (int) $payload['net_rate']
            : (isset($payload['face_value']) ? (int) $payload['face_value'] : null);
        $packageQuantity = $this->extractPackageQuantity($payload);

        if ($packageQuantity === null) {
            Log::channel(config('services.xs2.log_channel'))->warning('XS2 package_rate ticket is missing package quantity.', [
                'provider' => 'xs2event',
                'external_ticket_id' => (string) ($payload['ticket_id'] ?? $payload['id'] ?? ''),
                'event_id' => (string) ($payload['event_id'] ?? ''),
            ]);
        }

        return [
            'is_package_rate' => true,
            'package_quantity' => $packageQuantity,
            'package_price' => $packagePrice,
        ];
    }

    private function extractPackageQuantity(array $payload): ?int
    {
        $candidates = [
            $payload['package_quantity'] ?? null,
            $payload['package_size'] ?? null,
            $payload['package_qty'] ?? null,
            $payload['tickets_per_package'] ?? null,
            $payload['quantity_per_package'] ?? null,
            data_get($payload, 'options.package_quantity'),
            data_get($payload, 'options.package_size'),
            data_get($payload, 'options.package_qty'),
            data_get($payload, 'options.tickets_per_package'),
            data_get($payload, 'options.quantity_per_package'),
            data_get($payload, 'local_rates.package_quantity'),
            data_get($payload, 'local_rates.package_size'),
        ];

        foreach ($payload['sales_periods'] ?? [] as $period) {
            if (! is_array($period)) {
                continue;
            }

            $candidates[] = $period['package_quantity'] ?? null;
            $candidates[] = $period['package_size'] ?? null;
            $candidates[] = $period['package_qty'] ?? null;
            $candidates[] = $period['tickets_per_package'] ?? null;
        }

        $minOrder = (int) ($payload['min_order'] ?? 0);
        if ($minOrder > 1) {
            $candidates[] = $minOrder;
        }

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate >= 1) {
                return (int) $candidate;
            }
        }

        return null;
    }
}
