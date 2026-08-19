<?php

namespace App\Contracts;

interface Xs2ReservationService
{
    /**
     * Reservation payload fields have not been confirmed by an XS2 OpenAPI
     * document in this repository. An implementation must not send a request
     * until the required guest, hold-expiry, and ticket-selection fields are
     * documented and reviewed.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function createReservation(array $payload): array;
}
