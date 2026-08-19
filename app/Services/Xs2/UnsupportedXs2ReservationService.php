<?php

namespace App\Services\Xs2;

use App\Contracts\Xs2ReservationService;

class UnsupportedXs2ReservationService implements Xs2ReservationService
{
    public function createReservation(array $payload): array
    {
        throw new \LogicException(
            'XS2 reservation payload fields are not documented in this repository; no reservation request was sent.',
        );
    }
}
