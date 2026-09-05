<?php

namespace App\Contracts;

interface Xs2ReservationService
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function createReservation(array $payload): array;
}
