<?php

namespace App\Services\Xs2;

use App\Models\Xs2Ticket;

class Xs2TicketRuleService
{
    /**
     * @return list<string>
     */
    public function resolvedFlags(Xs2Ticket $ticket): array
    {
        $flags = is_array($ticket->flags) ? $ticket->flags : [];
        $payloadFlags = data_get($ticket->raw_payload, 'flags', []);
        if (is_array($payloadFlags)) {
            $flags = array_merge($flags, $payloadFlags);
        }

        $normalized = [];
        foreach ($flags as $flag) {
            if (! is_string($flag) && ! is_numeric($flag)) {
                continue;
            }

            $value = trim((string) $flag);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    public function allowedQuantities(Xs2Ticket $ticket): array
    {
        $flags = $this->resolvedFlags($ticket);
        $stock = max(0, (int) $ticket->stock);
        $minOrder = max(1, (int) ($ticket->min_order ?? 1));

        if (in_array('package_rate', $flags, true) || ($ticket->is_package_rate ?? false)) {
            if ($stock === 0) {
                return [];
            }

            return range($minOrder, $stock);
        }

        if ($stock < 1) {
            return [];
        }

        return array_values(array_filter(
            range(1, $stock),
            fn ($q) => $q >= $minOrder
                && (! in_array('pairs_only', $flags, true) || $q % 2 === 0)
                && (! in_array('no_max_minus_1', $flags, true) || $stock - $q !== 1),
        ));
    }
}
