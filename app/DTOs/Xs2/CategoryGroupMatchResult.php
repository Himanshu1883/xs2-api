<?php

namespace App\DTOs\Xs2;

class CategoryGroupMatchResult
{
    /**
     * @param  list<string>  $matchedFields
     * @param  list<array{stadium_detail_id:int,stadium_seat_id:?int,block:?string,section:?string,name:?string,stadium_seat_name:?string}>  $details
     * @param  list<array<string,mixed>>  $candidateGroups
     */
    public function __construct(
        public readonly float $score,
        public readonly string $method,
        public readonly array $matchedFields,
        public readonly array $details,
        public readonly array $candidateGroups,
    ) {}
}
