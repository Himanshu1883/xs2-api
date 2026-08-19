<?php

namespace App\Services\Xs2;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class Xs2TicketNormalizer
{
    public function __construct(private readonly Xs2PackageRateService $packageRates) {}

    public function normalize(array $p): array
    {
        $status = (string) ($p['ticket_status'] ?? $p['status'] ?? 'unavailable');
        if (! in_array($status, ['available', 'unavailable', 'deleted', 'disabled', 'on_request', 'returned', 'error'], true)) {
            Log::channel(config('services.xs2.log_channel'))->warning('Unknown XS2 ticket status.', ['ticket_status' => $status]);
        }

        $date = function ($value): ?Carbon {
            if (! filled($value)) {
                return null;
            }

            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        };

        $flags = is_array($p['flags'] ?? null) ? $p['flags'] : [];
        $package = $this->packageRates->resolveFromPayload($p);

        return [
            'external_ticket_id' => (string) ($p['ticket_id'] ?? $p['id'] ?? throw new \InvalidArgumentException('XS2 ticket ID is missing.')),
            'external_event_id' => (string) ($p['event_id'] ?? ''),
            'category_id' => data_get($p, 'category_id'),
            'category_name' => data_get($p, 'category_name'),
            // This is a product/validity variant; category mapping never uses it.
            'sub_category' => data_get($p, 'sub_category'),
            'ticket_title' => data_get($p, 'ticket_title', data_get($p, 'title')),
            'ticket_type' => data_get($p, 'type_ticket', data_get($p, 'ticket_type')),
            'ticket_status' => $status,
            'stock' => max(0, (int) ($p['stock'] ?? $p['available_quantity'] ?? 0)),
            'min_order' => max(1, (int) ($p['min_order'] ?? 1)),
            'net_rate' => isset($p['net_rate']) ? (int) $p['net_rate'] : null,
            'face_value' => isset($p['face_value']) ? (int) $p['face_value'] : null,
            'currency_code' => strtoupper((string) ($p['currency_code'] ?? $p['currency'] ?? '')) ?: null,
            'ticket_valid_from' => $date($p['ticket_validfrom'] ?? $p['ticket_valid_from'] ?? null),
            'ticket_valid_until' => $date($p['ticket_validuntil'] ?? $p['ticket_valid_until'] ?? null),
            'flags' => $flags,
            'is_package_rate' => $package['is_package_rate'],
            'package_quantity' => $package['package_quantity'],
            'package_price' => $package['package_price'],
            'options' => is_array($p['options'] ?? null) ? $p['options'] : [],
            'sales_periods' => is_array($p['sales_periods'] ?? null) ? $p['sales_periods'] : [],
            'raw_payload' => $p,
            'external_created_at' => $date($p['created'] ?? null),
            'external_updated_at' => $date($p['updated'] ?? null),
        ];
    }
}
