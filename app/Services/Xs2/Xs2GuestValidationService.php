<?php

namespace App\Services\Xs2;

use App\DTOs\Xs2\Xs2GuestValidationResult;
use App\Models\Xs2Ticket;
use Illuminate\Support\Facades\Schema;

/**
 * Validates guest nationality/province against away-team restrictions on XS2 tickets.
 */
class Xs2GuestValidationService
{
    public function __construct(
        private readonly Xs2AwayTeamContextService $awayTeam,
        private readonly Xs2TextNormalizer $text,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $guests
     */
    public function validateTicketGuests(Xs2Ticket $ticket, array $guests): Xs2GuestValidationResult
    {
        $flags = $ticket->flags ?? [];
        $blocksNationality = in_array('no_awayteam_nationality_allowed', $flags, true);
        $blocksProvince = in_array('no_awayteam_province_allowed', $flags, true);

        if (! $blocksNationality && ! $blocksProvince) {
            return $this->ok();
        }

        $event = $ticket->loadMissing('xs2Event')->xs2Event;
        if ($event === null) {
            return $this->fail('away_team_unknown', 'Away team context is unavailable for this ticket.');
        }

        $away = $this->awayTeam->resolve($event);
        $violations = [];

        foreach (array_values($guests) as $index => $guest) {
            if (! is_array($guest)) {
                continue;
            }

            if ($blocksNationality && $this->matchesAwayCountry($guest, $away['iso_country'])) {
                $violations[] = [
                    'guest_index' => $index,
                    'reason_code' => 'away_team_nationality_not_allowed',
                    'message' => sprintf(
                        'Guest %d nationality matches the away team country (%s), which is not allowed for this ticket.',
                        $index + 1,
                        $away['iso_country'] ?? 'unknown',
                    ),
                ];
            }

            if ($blocksProvince && $this->matchesAwayProvince($guest, $away['province'])) {
                $violations[] = [
                    'guest_index' => $index,
                    'reason_code' => 'away_team_province_not_allowed',
                    'message' => sprintf(
                        'Guest %d province matches the away team province (%s), which is not allowed for this ticket.',
                        $index + 1,
                        $away['province'] ?? 'unknown',
                    ),
                ];
            }
        }

        if ($violations === []) {
            return $this->ok();
        }

        return new Xs2GuestValidationResult(
            valid: false,
            reasonCode: $violations[0]['reason_code'],
            message: $violations[0]['message'],
            violations: $violations,
        );
    }

    /** @param array<string, mixed> $guest */
    private function matchesAwayCountry(array $guest, ?string $awayCountry): bool
    {
        if ($awayCountry === null) {
            return false;
        }

        $guestCountry = $this->guestCountry($guest);
        if ($guestCountry === null) {
            return false;
        }

        return $this->countriesMatch($guestCountry, $awayCountry);
    }

    /** @param array<string, mixed> $guest */
    private function matchesAwayProvince(array $guest, ?string $awayProvince): bool
    {
        if ($awayProvince === null) {
            return false;
        }

        $guestProvince = $this->guestProvince($guest);
        if ($guestProvince === null) {
            return false;
        }

        return $this->text->normalize($guestProvince) === $this->text->normalize($awayProvince);
    }

    /** @param array<string, mixed> $guest */
    private function guestCountry(array $guest): ?string
    {
        foreach (['country_of_residence', 'nationality', 'country'] as $key) {
            $value = $this->nullableString($guest[$key] ?? null);
            if ($value !== null) {
                return strtoupper($value);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $guest */
    private function guestProvince(array $guest): ?string
    {
        foreach (['province', 'state', 'region'] as $key) {
            $value = $this->nullableString($guest[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function countriesMatch(string $guestCountry, string $awayCountry): bool
    {
        $guestCountry = strtoupper(trim($guestCountry));
        $awayCountry = strtoupper(trim($awayCountry));

        if ($guestCountry === $awayCountry) {
            return true;
        }

        if (strlen($guestCountry) === 2 && strlen($awayCountry) === 3) {
            $alpha3 = $this->alpha3FromAlpha2($guestCountry);

            return $alpha3 !== null && $alpha3 === $awayCountry;
        }

        if (strlen($guestCountry) === 3 && strlen($awayCountry) === 2) {
            $alpha3 = $this->alpha3FromAlpha2($awayCountry);

            return $alpha3 !== null && $alpha3 === $guestCountry;
        }

        if (Schema::hasTable('countries')) {
            $guestName = $this->text->normalize($guestCountry);
            $awayName = $this->text->normalize($awayCountry);
            $rows = \Illuminate\Support\Facades\DB::table('countries')
                ->select(['sortname', 'name'])
                ->get();

            foreach ($rows as $row) {
                $aliases = array_filter([
                    $this->text->normalize((string) ($row->sortname ?? '')),
                    $this->text->normalize((string) ($row->name ?? '')),
                ]);
                if ($aliases === []) {
                    continue;
                }
                if (in_array($guestName, $aliases, true) && in_array($awayName, $aliases, true)) {
                    return true;
                }
            }
        }

        return $this->text->normalize($guestCountry) === $this->text->normalize($awayCountry);
    }

    private function alpha3FromAlpha2(string $alpha2): ?string
    {
        static $map = [
            'AT' => 'AUT', 'AU' => 'AUS', 'BE' => 'BEL', 'BR' => 'BRA', 'CA' => 'CAN',
            'CH' => 'CHE', 'DE' => 'DEU', 'ES' => 'ESP', 'FR' => 'FRA', 'GB' => 'GBR',
            'IE' => 'IRL', 'IT' => 'ITA', 'NL' => 'NLD', 'NO' => 'NOR', 'PL' => 'POL',
            'PT' => 'PRT', 'SE' => 'SWE', 'US' => 'USA',
        ];
        $alpha2 = strtoupper($alpha2);

        return $map[$alpha2] ?? null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function ok(): Xs2GuestValidationResult
    {
        return new Xs2GuestValidationResult(
            valid: true,
            reasonCode: 'valid',
            message: 'Guest data passed away-team restriction checks.',
        );
    }

    private function fail(string $code, string $message): Xs2GuestValidationResult
    {
        return new Xs2GuestValidationResult(
            valid: false,
            reasonCode: $code,
            message: $message,
        );
    }
}
