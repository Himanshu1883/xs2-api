<?php

namespace App\Services\Xs2;

use App\Models\Xs2Event;
use Illuminate\Support\Facades\Log;

/**
 * Resolves away-team country and province used for guest restriction validation.
 */
class Xs2AwayTeamContextService
{
    public function __construct(private readonly Xs2Client $client) {}

    /** @return array{team_name:?string, iso_country:?string, province:?string} */
    public function resolve(Xs2Event $event, bool $persist = true): array
    {
        if (filled($event->visitingteam_iso_country) || filled($event->visitingteam_province)) {
            return [
                'team_name' => $event->visitingteam_name,
                'iso_country' => $event->visitingteam_iso_country,
                'province' => $event->visitingteam_province,
            ];
        }

        $context = $this->fetchFromXs2($event);

        if ($persist && ($context['iso_country'] !== null || $context['province'] !== null)) {
            $event->update([
                'visitingteam_iso_country' => $context['iso_country'],
                'visitingteam_province' => $context['province'],
            ]);
        }

        return $context;
    }

    /** @return array{team_name:?string, iso_country:?string, province:?string} */
    private function fetchFromXs2(Xs2Event $event): array
    {
        $teamId = filled($event->visitingteam_id) ? (string) $event->visitingteam_id : null;
        if ($teamId === null) {
            return [
                'team_name' => $event->visitingteam_name,
                'iso_country' => null,
                'province' => null,
            ];
        }

        try {
            $team = $this->client->getTeam($teamId);
            $isoCountry = $this->nullableUpper((string) ($team['iso_country'] ?? ''));
            $province = null;

            $venueId = $team['venue_id'] ?? null;
            if (is_string($venueId) && $venueId !== '') {
                $venue = $this->client->getVenue($venueId);
                $province = $this->nullableString($venue['province'] ?? $venue['city'] ?? null);
            }

            return [
                'team_name' => $this->nullableString($team['official_name'] ?? $event->visitingteam_name),
                'iso_country' => $isoCountry,
                'province' => $province,
            ];
        } catch (\Throwable $exception) {
            Log::channel(config('xs2.log_channel', 'stack'))->warning('XS2 away-team context could not be resolved.', [
                'provider' => 'xs2event',
                'external_event_id' => $event->external_event_id,
                'visitingteam_id' => $teamId,
                'error_class' => $exception::class,
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            return [
                'team_name' => $event->visitingteam_name,
                'iso_country' => null,
                'province' => null,
            ];
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function nullableUpper(string $value): ?string
    {
        $string = strtoupper(trim($value));

        return $string === '' ? null : $string;
    }
}
