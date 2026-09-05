<?php

namespace App\Services\Xs2;

use App\Contracts\Xs2ReservationService as Xs2ReservationServiceContract;

class Xs2ReservationService implements Xs2ReservationServiceContract
{
    public function __construct(private readonly Xs2Client $client) {}

    /** @param array<string,mixed> $payload */
    public function createReservation(array $payload): array
    {
        $result = $this->client->createReservationDetailed($payload);
        if (! $result['success']) {
            throw new \RuntimeException((string) ($result['message'] ?? 'XS2 reservation failed.'));
        }

        return $result['data'];
    }
}
