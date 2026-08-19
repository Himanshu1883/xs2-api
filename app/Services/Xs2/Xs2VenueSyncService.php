<?php

namespace App\Services\Xs2;

use App\Models\Xs2Event;
use App\Models\Xs2StadiumMapping;
use App\Models\Xs2Venue;
use App\Services\Mapping\StadiumMappingService;
use Carbon\Carbon;

class Xs2VenueSyncService
{
    /**
     * XS2's venue endpoint supplies ISO alpha-3 codes while the legacy
     * catalogue commonly stores alpha-2 codes. Keep the conversion local so
     * venue mapping never depends on a provider-specific country name.
     *
     * @var array<string, string>
     */
    private const ISO_ALPHA3_TO_ALPHA2 = [
        'AFG' => 'AF', 'ALA' => 'AX', 'ALB' => 'AL', 'DZA' => 'DZ', 'ASM' => 'AS',
        'AND' => 'AD', 'AGO' => 'AO', 'AIA' => 'AI', 'ATA' => 'AQ', 'ATG' => 'AG',
        'ARG' => 'AR', 'ARM' => 'AM', 'ABW' => 'AW', 'AUS' => 'AU', 'AUT' => 'AT',
        'AZE' => 'AZ', 'BHS' => 'BS', 'BHR' => 'BH', 'BGD' => 'BD', 'BRB' => 'BB',
        'BLR' => 'BY', 'BEL' => 'BE', 'BLZ' => 'BZ', 'BEN' => 'BJ', 'BMU' => 'BM',
        'BTN' => 'BT', 'BOL' => 'BO', 'BES' => 'BQ', 'BIH' => 'BA', 'BWA' => 'BW',
        'BVT' => 'BV', 'BRA' => 'BR', 'IOT' => 'IO', 'BRN' => 'BN', 'BGR' => 'BG',
        'BFA' => 'BF', 'BDI' => 'BI', 'CPV' => 'CV', 'KHM' => 'KH', 'CMR' => 'CM',
        'CAN' => 'CA', 'CYM' => 'KY', 'CAF' => 'CF', 'TCD' => 'TD', 'CHL' => 'CL',
        'CHN' => 'CN', 'CXR' => 'CX', 'CCK' => 'CC', 'COL' => 'CO', 'COM' => 'KM',
        'COG' => 'CG', 'COD' => 'CD', 'COK' => 'CK', 'CRI' => 'CR', 'CIV' => 'CI',
        'HRV' => 'HR', 'CUB' => 'CU', 'CUW' => 'CW', 'CYP' => 'CY', 'CZE' => 'CZ',
        'DNK' => 'DK', 'DJI' => 'DJ', 'DMA' => 'DM', 'DOM' => 'DO', 'ECU' => 'EC',
        'EGY' => 'EG', 'SLV' => 'SV', 'GNQ' => 'GQ', 'ERI' => 'ER', 'EST' => 'EE',
        'SWZ' => 'SZ', 'ETH' => 'ET', 'FLK' => 'FK', 'FRO' => 'FO', 'FJI' => 'FJ',
        'FIN' => 'FI', 'FRA' => 'FR', 'GUF' => 'GF', 'PYF' => 'PF', 'ATF' => 'TF',
        'GAB' => 'GA', 'GMB' => 'GM', 'GEO' => 'GE', 'DEU' => 'DE', 'GHA' => 'GH',
        'GIB' => 'GI', 'GRC' => 'GR', 'GRL' => 'GL', 'GRD' => 'GD', 'GLP' => 'GP',
        'GUM' => 'GU', 'GTM' => 'GT', 'GGY' => 'GG', 'GIN' => 'GN', 'GNB' => 'GW',
        'GUY' => 'GY', 'HTI' => 'HT', 'HMD' => 'HM', 'VAT' => 'VA', 'HND' => 'HN',
        'HKG' => 'HK', 'HUN' => 'HU', 'ISL' => 'IS', 'IND' => 'IN', 'IDN' => 'ID',
        'IRN' => 'IR', 'IRQ' => 'IQ', 'IRL' => 'IE', 'IMN' => 'IM', 'ISR' => 'IL',
        'ITA' => 'IT', 'JAM' => 'JM', 'JPN' => 'JP', 'JEY' => 'JE', 'JOR' => 'JO',
        'KAZ' => 'KZ', 'KEN' => 'KE', 'KIR' => 'KI', 'PRK' => 'KP', 'KOR' => 'KR',
        'KWT' => 'KW', 'KGZ' => 'KG', 'LAO' => 'LA', 'LVA' => 'LV', 'LBN' => 'LB',
        'LSO' => 'LS', 'LBR' => 'LR', 'LBY' => 'LY', 'LIE' => 'LI', 'LTU' => 'LT',
        'LUX' => 'LU', 'MAC' => 'MO', 'MDG' => 'MG', 'MWI' => 'MW', 'MYS' => 'MY',
        'MDV' => 'MV', 'MLI' => 'ML', 'MLT' => 'MT', 'MHL' => 'MH', 'MTQ' => 'MQ',
        'MRT' => 'MR', 'MUS' => 'MU', 'MYT' => 'YT', 'MEX' => 'MX', 'FSM' => 'FM',
        'MDA' => 'MD', 'MCO' => 'MC', 'MNG' => 'MN', 'MNE' => 'ME', 'MSR' => 'MS',
        'MAR' => 'MA', 'MOZ' => 'MZ', 'MMR' => 'MM', 'NAM' => 'NA', 'NRU' => 'NR',
        'NPL' => 'NP', 'NLD' => 'NL', 'NCL' => 'NC', 'NZL' => 'NZ', 'NIC' => 'NI',
        'NER' => 'NE', 'NGA' => 'NG', 'NIU' => 'NU', 'NFK' => 'NF', 'MKD' => 'MK',
        'MNP' => 'MP', 'NOR' => 'NO', 'OMN' => 'OM', 'PAK' => 'PK', 'PLW' => 'PW',
        'PSE' => 'PS', 'PAN' => 'PA', 'PNG' => 'PG', 'PRY' => 'PY', 'PER' => 'PE',
        'PHL' => 'PH', 'PCN' => 'PN', 'POL' => 'PL', 'PRT' => 'PT', 'PRI' => 'PR',
        'QAT' => 'QA', 'REU' => 'RE', 'ROU' => 'RO', 'RUS' => 'RU', 'RWA' => 'RW',
        'BLM' => 'BL', 'SHN' => 'SH', 'KNA' => 'KN', 'LCA' => 'LC', 'MAF' => 'MF',
        'SPM' => 'PM', 'VCT' => 'VC', 'WSM' => 'WS', 'SMR' => 'SM', 'STP' => 'ST',
        'SAU' => 'SA', 'SEN' => 'SN', 'SRB' => 'RS', 'SYC' => 'SC', 'SLE' => 'SL',
        'SGP' => 'SG', 'SXM' => 'SX', 'SVK' => 'SK', 'SVN' => 'SI', 'SLB' => 'SB',
        'SOM' => 'SO', 'ZAF' => 'ZA', 'SGS' => 'GS', 'SSD' => 'SS', 'ESP' => 'ES',
        'LKA' => 'LK', 'SDN' => 'SD', 'SUR' => 'SR', 'SJM' => 'SJ', 'SWE' => 'SE',
        'CHE' => 'CH', 'SYR' => 'SY', 'TWN' => 'TW', 'TJK' => 'TJ', 'TZA' => 'TZ',
        'THA' => 'TH', 'TLS' => 'TL', 'TGO' => 'TG', 'TKL' => 'TK', 'TON' => 'TO',
        'TTO' => 'TT', 'TUN' => 'TN', 'TUR' => 'TR', 'TKM' => 'TM', 'TCA' => 'TC',
        'TUV' => 'TV', 'UGA' => 'UG', 'UKR' => 'UA', 'ARE' => 'AE', 'GBR' => 'GB',
        'USA' => 'US', 'UMI' => 'UM', 'URY' => 'UY', 'UZB' => 'UZ', 'VUT' => 'VU',
        'VEN' => 'VE', 'VNM' => 'VN', 'VGB' => 'VG', 'VIR' => 'VI', 'WLF' => 'WF',
        'ESH' => 'EH', 'YEM' => 'YE', 'ZMB' => 'ZM', 'ZWE' => 'ZW',
        // Kosovo is not assigned an official ISO alpha-2 code, but XK/XKX
        // are widely used by ticket providers and legacy catalogues.
        'XKX' => 'XK',
    ];

    /** @var array<string, string> */
    private const ISO_ALPHA2_ALIASES = [
        'UK' => 'GB',
        'EL' => 'GR',
    ];

    public function __construct(
        private readonly Xs2Client $client,
        private readonly StadiumMappingService $mappings,
    ) {}

    /** @return array{venue:Xs2Venue,mapping:Xs2StadiumMapping,created:bool,updated:bool} */
    public function syncByExternalVenueId(string $externalVenueId): array
    {
        $externalVenueId = trim($externalVenueId);
        if ($externalVenueId === '') {
            throw new \InvalidArgumentException('XS2 venue ID is missing.');
        }

        $payload = $this->unwrapVenue($this->client->getVenue($externalVenueId));
        $payload['venue_id'] ??= $externalVenueId;

        return $this->syncPayload($payload);
    }

    /** @return array{venue:Xs2Venue,mapping:Xs2StadiumMapping,created:bool,updated:bool} */
    public function syncForEvent(Xs2Event $event): array
    {
        $source = is_array($event->raw_payload) ? $event->raw_payload : [];
        $externalVenueId = (string) (data_get($source, 'venue_id') ?? $event->venue_id ?? '');
        if ($externalVenueId === '') {
            throw new \InvalidArgumentException('XS2 event has no venue ID.');
        }

        $cached = Xs2Venue::query()
            ->with('stadiumMapping')
            ->where('external_venue_id', $externalVenueId)
            ->first();
        if ($cached?->stadiumMapping) {
            return [
                'venue' => $cached,
                'mapping' => $cached->stadiumMapping,
                'created' => false,
                'updated' => false,
            ];
        }

        $embedded = data_get($source, 'venue');
        $payload = is_array($embedded) ? $embedded : [];
        if (! $this->hasUsableDetails($payload)) {
            $fallback = [
                'venue_id' => $externalVenueId,
                'venue_name' => $event->venue_name,
                'city' => $event->city,
                'country_code' => $event->iso_country,
                'latitude' => $event->latitude,
                'longitude' => $event->longitude,
            ];
            $payload = $this->hasUsableDetails($fallback)
                ? $fallback
                : $this->unwrapVenue($this->client->getVenue($externalVenueId));
        }

        $payload['venue_id'] ??= $externalVenueId;

        return $this->syncPayload($payload);
    }

    /**
     * Persist (or refresh) an XS2 venue row from event fields without calling
     * the XS2 venue API or running stadium resolution.
     */
    public function ensureFromEvent(Xs2Event $event): ?Xs2Venue
    {
        $externalVenueId = trim((string) ($event->venue_id ?? ''));
        if ($externalVenueId === '') {
            return null;
        }

        $payload = [
            'venue_id' => $externalVenueId,
            'venue_name' => $event->venue_name,
            'city' => $event->city,
            'country_code' => $event->iso_country,
            'latitude' => $event->latitude,
            'longitude' => $event->longitude,
        ];

        $attributes = $this->normalize($payload);
        $venue = Xs2Venue::query()->firstOrNew(['external_venue_id' => $attributes['external_venue_id']]);
        $venue->fill($attributes);
        $venue->save();

        return $venue;
    }

    /** @return array{venue:Xs2Venue,mapping:Xs2StadiumMapping,created:bool,updated:bool} */
    public function syncPayload(array $payload): array
    {
        $attributes = $this->normalize($payload);
        $venue = Xs2Venue::query()->firstOrNew(['external_venue_id' => $attributes['external_venue_id']]);
        $created = ! $venue->exists;
        $venue->fill($attributes);
        $updated = $created || $venue->isDirty();
        $venue->save();

        return [
            'venue' => $venue,
            'mapping' => $this->mappings->resolve($venue),
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /** @return array<string,mixed> */
    private function normalize(array $payload): array
    {
        $id = $payload['venue_id'] ?? $payload['id'] ?? null;
        if (! is_scalar($id) || trim((string) $id) === '') {
            throw new \InvalidArgumentException('XS2 venue ID is missing.');
        }

        return [
            'external_venue_id' => (string) $id,
            'venue_name' => $this->string($payload, ['venue_name', 'official_name', 'name']),
            'slug' => $this->string($payload, ['slug']),
            'address' => $this->string($payload, ['address', 'address_line', 'streetname']),
            'postal_code' => $this->string($payload, ['postal_code', 'postalcode', 'postcode', 'zip']),
            'city_name' => $this->string($payload, ['city', 'city_name']),
            'country_name' => $this->countryName($payload),
            'country_code' => $this->countryCode($payload),
            'latitude' => $this->numeric($payload['latitude'] ?? $payload['lat'] ?? null),
            'longitude' => $this->numeric($payload['longitude'] ?? $payload['lng'] ?? $payload['lon'] ?? null),
            'raw_payload' => $payload,
            'external_created_at' => $this->date($payload['created'] ?? $payload['created_at'] ?? null),
            'external_updated_at' => $this->date($payload['updated'] ?? $payload['updated_at'] ?? null),
            'last_synced_at' => now(),
        ];
    }

    private function unwrapVenue(array $response): array
    {
        $venue = data_get($response, 'venue', $response);

        if (! is_array($venue)) {
            throw new \UnexpectedValueException('XS2 venue response has an unexpected structure.');
        }

        return $venue;
    }

    private function hasUsableDetails(array $payload): bool
    {
        return filled($payload['city'] ?? $payload['city_name'] ?? null)
            && $this->countryCode($payload) !== null;
    }

    private function countryName(array $payload): ?string
    {
        foreach (['country_name', 'country'] as $key) {
            $value = $this->string($payload, [$key]);
            if ($value !== null && ! $this->looksLikeCountryCode($value)) {
                return $value;
            }
        }

        return null;
    }

    private function countryCode(array $payload): ?string
    {
        foreach (['country_code', 'iso_country', 'country_iso', 'country'] as $key) {
            $value = $this->string($payload, [$key]);
            $code = $value === null ? null : $this->normalizeCountryCode($value);
            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    private function normalizeCountryCode(string $value): ?string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $value));
        if (! $this->looksLikeCountryCode($code)) {
            return null;
        }

        if (strlen($code) === 2) {
            return self::ISO_ALPHA2_ALIASES[$code] ?? $code;
        }

        return self::ISO_ALPHA3_TO_ALPHA2[$code] ?? $code;
    }

    private function looksLikeCountryCode(string $value): bool
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $value));

        return strlen($code) === 2 || strlen($code) === 3;
    }

    /** @param list<string> $keys */
    private function string(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_scalar($payload[$key] ?? null) && trim((string) $payload[$key]) !== '') {
                return trim((string) $payload[$key]);
            }
        }

        return null;
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
