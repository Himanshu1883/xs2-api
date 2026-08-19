<?php

namespace App\Services\Xs2;

use App\Models\Xs2Ticket;

class Xs2TicketRuleService
{
    public function allowedQuantities(Xs2Ticket $ticket): array
    {
        if (in_array('package_rate', $ticket->flags ?? [], true) || ($ticket->is_package_rate ?? false)) {
            $stock = max(0, (int) $ticket->stock);
            if ($stock === 0) {
                return [];
            }

            return range(max(1, (int) $ticket->min_order), $stock);
        }

        return array_values(array_filter(range(1, $ticket->stock), fn ($q) => $q >= $ticket->min_order && (! in_array('pairs_only', $ticket->flags ?? [], true) || $q % 2 === 0) && (! in_array('no_max_minus_1', $ticket->flags ?? [], true) || $ticket->stock - $q !== 1)));
    }
}
