<?php

namespace App\Services\Xs2;

use App\Models\SbOrderAttendee;
use App\Models\Xs2OrderAttendee;
use Illuminate\Support\Collection;

/**
 * Build the XS2 booking-order guest-data PUT body:
 * `{ items: [ { ticket_id, guests: [ ... ] } ] }`.
 */
class Xs2GuestDataPayloadBuilder
{
    /**
     * Official XS2 guest-data fields (PUT /v1/bookingorders/{id}/guestdata).
     *
     * @see https://docs.xs2event.com/guest-data.html
     *
     * @var list<string>
     */
    public const GUEST_FIELD_KEYS = [
        'first_name',
        'last_name',
        'passport_number',
        'contact_email',
        'contact_phone',
        'lead_guest',
        'date_of_birth',
        'gender',
        'country_of_residence',
        'street_name',
        'city',
        'zip',
        'province',
        'guest_id',
    ];

    /** @var list<string> */
    public const MERGED_EXISTING_GUEST_KEYS = [
        'reservation_id',
        'ticket_id',
    ];

    private const DEFAULT_STREET_NAME = 'Not provided';

    private const DEFAULT_CITY = 'Barcelona';

    private const DEFAULT_ZIP = '00000';

    /**
     * @param  Collection<int, Xs2OrderAttendee|SbOrderAttendee>|iterable<int, Xs2OrderAttendee|SbOrderAttendee|array<string, mixed>>  $attendees
     * @param  list<array<string, mixed>>  $existingGuests
     * @return array{items: list<array{ticket_id: string, guests: list<array<string, mixed>>}>}
     */
    public function build(
        string $ticketId,
        iterable $attendees,
        array $existingGuests = [],
        ?string $defaultCity = null,
    ): array {
        $guests = [];
        foreach (Collection::make($attendees)->values() as $index => $attendee) {
            $guests[] = $this->guestFromAttendee(
                $attendee,
                $index,
                is_array($existingGuests[$index] ?? null) ? $existingGuests[$index] : [],
                $defaultCity,
            );
        }

        return [
            'items' => [[
                'ticket_id' => $ticketId,
                'guests' => $guests,
            ]],
        ];
    }

    /**
     * @param  Xs2OrderAttendee|SbOrderAttendee|array<string, mixed>  $attendee
     */
    public function attendeeHasField(object|array $attendee, string $requirement): bool
    {
        $keys = match ($requirement) {
            'first_name', 'firstname' => ['first_name', 'firstname'],
            'last_name', 'lastname' => ['last_name', 'lastname'],
            'date_of_birth', 'dob' => ['dob', 'date_of_birth'],
            'passport_number', 'passport' => ['passport', 'passport_number'],
            'country_of_residence', 'nationality', 'country' => ['nationality', 'country_of_residence', 'country'],
            'contact_email', 'email' => ['email', 'contact_email'],
            'contact_phone', 'phone', 'mobile' => ['phone', 'contact_phone', 'mobile'],
            'street_name', 'street', 'address' => ['street_name', 'street', 'address', 'address_line_1'],
            'zip', 'postal_code', 'postcode' => ['zip', 'postal_code', 'postcode', 'zipcode'],
            'gender' => ['gender'],
            'city' => ['city'],
            'province', 'state' => ['province', 'state'],
            default => [$requirement],
        };

        return $this->firstString($attendee, $keys) !== null;
    }

    /**
     * @param  Xs2OrderAttendee|SbOrderAttendee|array<string, mixed>  $attendee
     * @param  array<string, mixed>  $existingGuest
     * @return array<string, mixed>
     */
    private function guestFromAttendee(
        object|array $attendee,
        int $index,
        array $existingGuest,
        ?string $defaultCity,
    ): array {
        $country = $this->normalizeCountryAlpha3(
            $this->firstString($attendee, ['nationality', 'country_of_residence', 'country']),
        );

        $gender = $this->firstString($attendee, ['gender']);
        if ($gender !== null) {
            $gender = match (strtolower($gender)) {
                'other' => 'unknown',
                default => strtolower($gender),
            };
        }

        $existingGuestId = $existingGuest['guest_id'] ?? null;
        if (is_array($existingGuestId)) {
            $existingGuestId = $existingGuestId['value'] ?? null;
        }

        $guestId = $this->nullableString($existingGuestId)
            ?? $this->firstString($attendee, ['guest_id']);

        $guest = [
            'first_name' => $this->firstString($attendee, ['first_name', 'firstname']),
            'last_name' => $this->firstString($attendee, ['last_name', 'lastname']),
            'passport_number' => $this->firstString($attendee, ['passport', 'passport_number']),
            'contact_email' => $this->firstString($attendee, ['email', 'contact_email']),
            'contact_phone' => $this->normalizeContactPhone(
                $this->firstString($attendee, ['phone', 'contact_phone', 'mobile']),
            ),
            'lead_guest' => $index === 0,
            'date_of_birth' => $this->firstString($attendee, ['dob', 'date_of_birth']),
            'gender' => $gender,
            'country_of_residence' => $country,
            'street_name' => $this->resolveStreetNameWithDefault($attendee),
            'city' => $this->resolveCity($attendee, $defaultCity),
            'zip' => $this->resolveZip($attendee),
            'guest_id' => $guestId,
        ];

        $province = $this->firstString($attendee, ['province', 'state']);
        if ($province !== null) {
            $guest['province'] = $province;
        }

        foreach (self::MERGED_EXISTING_GUEST_KEYS as $key) {
            $value = $this->nullableString($existingGuest[$key] ?? null);
            if ($value !== null) {
                $guest[$key] = $value;
            }
        }

        return $this->omitNullValues($guest);
    }

    /**
     * @param  Xs2OrderAttendee|SbOrderAttendee|array<string, mixed>  $attendee
     */
    private function resolveStreetNameWithDefault(object|array $attendee): string
    {
        return $this->resolveStreetName($attendee) ?? self::DEFAULT_STREET_NAME;
    }

    /**
     * @param  Xs2OrderAttendee|SbOrderAttendee|array<string, mixed>  $attendee
     */
    private function resolveCity(object|array $attendee, ?string $defaultCity): string
    {
        $city = $this->firstString($attendee, ['city']);

        return $city ?? $defaultCity ?? self::DEFAULT_CITY;
    }

    /**
     * @param  Xs2OrderAttendee|SbOrderAttendee|array<string, mixed>  $attendee
     */
    private function resolveZip(object|array $attendee): string
    {
        return $this->firstString($attendee, ['zip', 'postal_code', 'postcode', 'zipcode'])
            ?? self::DEFAULT_ZIP;
    }

    private function normalizeContactPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        if (! str_starts_with($phone, '+')) {
            return '+'.$phone;
        }

        return $phone;
    }

    /**
     * @param  array<string, mixed>  $guest
     * @return array<string, mixed>
     */
    private function omitNullValues(array $guest): array
    {
        return array_filter(
            $guest,
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * XS2 docs: street_name = "streetname + housenumber".
     *
     * @param  Xs2OrderAttendee|SbOrderAttendee|array<string, mixed>  $attendee
     */
    private function resolveStreetName(object|array $attendee): ?string
    {
        $street = $this->firstString($attendee, ['street_name', 'street', 'address', 'address_line_1']);
        if ($street === null) {
            return null;
        }

        $houseNumber = $this->firstString($attendee, ['house_number', 'housenumber', 'house_no']);
        if ($houseNumber !== null && ! str_contains($street, $houseNumber)) {
            return $street.' '.$houseNumber;
        }

        return $street;
    }

    /**
     * Normalize to ISO 3166-1 alpha-3 (uppercase, 3 chars).
     *
     * Two-letter codes are converted using a lookup table; values already
     * three characters long are uppercased and returned as-is.
     */
    private function normalizeCountryAlpha3(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtoupper(trim($value));
        if ($value === '') {
            return null;
        }

        if (strlen($value) === 3) {
            return $value;
        }

        if (strlen($value) === 2) {
            return self::ISO_ALPHA2_TO_ALPHA3[$value] ?? $value;
        }

        $normalizedName = preg_replace('/[^A-Z]/', '', $value) ?? $value;
        if ($normalizedName !== '' && isset(self::COUNTRY_NAME_TO_ALPHA3[$normalizedName])) {
            return self::COUNTRY_NAME_TO_ALPHA3[$normalizedName];
        }

        return $value;
    }

    /**
     * Common country names → ISO 3166-1 alpha-3 (normalized to uppercase letters only).
     *
     * @var array<string, string>
     */
    private const COUNTRY_NAME_TO_ALPHA3 = [
        'AFGHANISTAN' => 'AFG', 'ALBANIA' => 'ALB', 'ALGERIA' => 'DZA', 'ANDORRA' => 'AND',
        'ANGOLA' => 'AGO', 'ARGENTINA' => 'ARG', 'ARMENIA' => 'ARM', 'AUSTRALIA' => 'AUS',
        'AUSTRIA' => 'AUT', 'AZERBAIJAN' => 'AZE', 'BAHRAIN' => 'BHR', 'BANGLADESH' => 'BGD',
        'BELARUS' => 'BLR', 'BELGIUM' => 'BEL', 'BOLIVIA' => 'BOL', 'BOSNIAANDHERZEGOVINA' => 'BIH',
        'BRAZIL' => 'BRA', 'BULGARIA' => 'BGR', 'CAMBODIA' => 'KHM', 'CAMEROON' => 'CMR',
        'CANADA' => 'CAN', 'CHILE' => 'CHL', 'CHINA' => 'CHN', 'COLOMBIA' => 'COL',
        'COSTARICA' => 'CRI', 'CROATIA' => 'HRV', 'CUBA' => 'CUB', 'CYPRUS' => 'CYP',
        'CZECHIA' => 'CZE', 'CZECHREPUBLIC' => 'CZE', 'DENMARK' => 'DNK', 'ECUADOR' => 'ECU',
        'EGYPT' => 'EGY', 'ESTONIA' => 'EST', 'FINLAND' => 'FIN', 'FRANCE' => 'FRA',
        'GEORGIA' => 'GEO', 'GERMANY' => 'DEU', 'GHANA' => 'GHA', 'GREECE' => 'GRC',
        'HONGKONG' => 'HKG', 'HUNGARY' => 'HUN', 'ICELAND' => 'ISL', 'INDIA' => 'IND',
        'INDONESIA' => 'IDN', 'IRAN' => 'IRN', 'IRAQ' => 'IRQ', 'IRELAND' => 'IRL',
        'ISRAEL' => 'ISR', 'ITALY' => 'ITA', 'JAMAICA' => 'JAM', 'JAPAN' => 'JPN',
        'JORDAN' => 'JOR', 'KAZAKHSTAN' => 'KAZ', 'KENYA' => 'KEN', 'KUWAIT' => 'KWT',
        'LATVIA' => 'LVA', 'LEBANON' => 'LBN', 'LITHUANIA' => 'LTU', 'LUXEMBOURG' => 'LUX',
        'MALAYSIA' => 'MYS', 'MALTA' => 'MLT', 'MEXICO' => 'MEX', 'MOLDOVA' => 'MDA',
        'MONTENEGRO' => 'MNE', 'MOROCCO' => 'MAR', 'NETHERLANDS' => 'NLD', 'NEWZEALAND' => 'NZL',
        'NIGERIA' => 'NGA', 'NORWAY' => 'NOR', 'OMAN' => 'OMN', 'PAKISTAN' => 'PAK',
        'PANAMA' => 'PAN', 'PERU' => 'PER', 'PHILIPPINES' => 'PHL', 'POLAND' => 'POL',
        'PORTUGAL' => 'PRT', 'QATAR' => 'QAT', 'ROMANIA' => 'ROU', 'RUSSIA' => 'RUS',
        'SAUDIARABIA' => 'SAU', 'SERBIA' => 'SRB', 'SINGAPORE' => 'SGP', 'SLOVAKIA' => 'SVK',
        'SLOVENIA' => 'SVN', 'SOUTHAFRICA' => 'ZAF', 'SOUTHKOREA' => 'KOR', 'SPAIN' => 'ESP',
        'SRILANKA' => 'LKA', 'SWEDEN' => 'SWE', 'SWITZERLAND' => 'CHE', 'SYRIA' => 'SYR',
        'TAIWAN' => 'TWN', 'THAILAND' => 'THA', 'TUNISIA' => 'TUN', 'TURKEY' => 'TUR',
        'UKRAINE' => 'UKR', 'UNITEDARABEMIRATES' => 'ARE', 'UNITEDKINGDOM' => 'GBR',
        'UNITEDSTATES' => 'USA', 'USA' => 'USA', 'UK' => 'GBR', 'URUGUAY' => 'URY',
        'UZBEKISTAN' => 'UZB', 'VENEZUELA' => 'VEN', 'VIETNAM' => 'VNM',
    ];

    /**
     * ISO 3166-1 alpha-2 → alpha-3 mapping for common countries in the XS2 guest-data domain.
     *
     * @var array<string, string>
     */
    private const ISO_ALPHA2_TO_ALPHA3 = [
        'AF' => 'AFG', 'AL' => 'ALB', 'DZ' => 'DZA', 'AD' => 'AND', 'AO' => 'AGO',
        'AG' => 'ATG', 'AR' => 'ARG', 'AM' => 'ARM', 'AU' => 'AUS', 'AT' => 'AUT',
        'AZ' => 'AZE', 'BS' => 'BHS', 'BH' => 'BHR', 'BD' => 'BGD', 'BB' => 'BRB',
        'BY' => 'BLR', 'BE' => 'BEL', 'BZ' => 'BLZ', 'BJ' => 'BEN', 'BT' => 'BTN',
        'BO' => 'BOL', 'BA' => 'BIH', 'BW' => 'BWA', 'BR' => 'BRA', 'BN' => 'BRN',
        'BG' => 'BGR', 'BF' => 'BFA', 'BI' => 'BDI', 'CV' => 'CPV', 'KH' => 'KHM',
        'CM' => 'CMR', 'CA' => 'CAN', 'CF' => 'CAF', 'TD' => 'TCD', 'CL' => 'CHL',
        'CN' => 'CHN', 'CO' => 'COL', 'KM' => 'COM', 'CG' => 'COG', 'CD' => 'COD',
        'CR' => 'CRI', 'CI' => 'CIV', 'HR' => 'HRV', 'CU' => 'CUB', 'CY' => 'CYP',
        'CZ' => 'CZE', 'DK' => 'DNK', 'DJ' => 'DJI', 'DM' => 'DMA', 'DO' => 'DOM',
        'EC' => 'ECU', 'EG' => 'EGY', 'SV' => 'SLV', 'GQ' => 'GNQ', 'ER' => 'ERI',
        'EE' => 'EST', 'SZ' => 'SWZ', 'ET' => 'ETH', 'FJ' => 'FJI', 'FI' => 'FIN',
        'FR' => 'FRA', 'GA' => 'GAB', 'GM' => 'GMB', 'GE' => 'GEO', 'DE' => 'DEU',
        'GH' => 'GHA', 'GR' => 'GRC', 'GD' => 'GRD', 'GT' => 'GTM', 'GN' => 'GIN',
        'GW' => 'GNB', 'GY' => 'GUY', 'HT' => 'HTI', 'HN' => 'HND', 'HU' => 'HUN',
        'IS' => 'ISL', 'IN' => 'IND', 'ID' => 'IDN', 'IR' => 'IRN', 'IQ' => 'IRQ',
        'IE' => 'IRL', 'IL' => 'ISR', 'IT' => 'ITA', 'JM' => 'JAM', 'JP' => 'JPN',
        'JO' => 'JOR', 'KZ' => 'KAZ', 'KE' => 'KEN', 'KI' => 'KIR', 'KP' => 'PRK',
        'KR' => 'KOR', 'KW' => 'KWT', 'KG' => 'KGZ', 'LA' => 'LAO', 'LV' => 'LVA',
        'LB' => 'LBN', 'LS' => 'LSO', 'LR' => 'LBR', 'LY' => 'LBY', 'LI' => 'LIE',
        'LT' => 'LTU', 'LU' => 'LUX', 'MG' => 'MDG', 'MW' => 'MWI', 'MY' => 'MYS',
        'MV' => 'MDV', 'ML' => 'MLI', 'MT' => 'MLT', 'MH' => 'MHL', 'MR' => 'MRT',
        'MU' => 'MUS', 'MX' => 'MEX', 'FM' => 'FSM', 'MD' => 'MDA', 'MC' => 'MCO',
        'MN' => 'MNG', 'ME' => 'MNE', 'MA' => 'MAR', 'MZ' => 'MOZ', 'MM' => 'MMR',
        'NA' => 'NAM', 'NR' => 'NRU', 'NP' => 'NPL', 'NL' => 'NLD', 'NZ' => 'NZL',
        'NI' => 'NIC', 'NE' => 'NER', 'NG' => 'NGA', 'MK' => 'MKD', 'NO' => 'NOR',
        'OM' => 'OMN', 'PK' => 'PAK', 'PW' => 'PLW', 'PA' => 'PAN', 'PG' => 'PNG',
        'PY' => 'PRY', 'PE' => 'PER', 'PH' => 'PHL', 'PL' => 'POL', 'PT' => 'PRT',
        'QA' => 'QAT', 'RO' => 'ROU', 'RU' => 'RUS', 'RW' => 'RWA', 'KN' => 'KNA',
        'LC' => 'LCA', 'VC' => 'VCT', 'WS' => 'WSM', 'SM' => 'SMR', 'ST' => 'STP',
        'SA' => 'SAU', 'SN' => 'SEN', 'RS' => 'SRB', 'SC' => 'SYC', 'SL' => 'SLE',
        'SG' => 'SGP', 'SK' => 'SVK', 'SI' => 'SVN', 'SB' => 'SLB', 'SO' => 'SOM',
        'ZA' => 'ZAF', 'SS' => 'SSD', 'ES' => 'ESP', 'LK' => 'LKA', 'SD' => 'SDN',
        'SR' => 'SUR', 'SE' => 'SWE', 'CH' => 'CHE', 'SY' => 'SYR', 'TW' => 'TWN',
        'TJ' => 'TJK', 'TZ' => 'TZA', 'TH' => 'THA', 'TL' => 'TLS', 'TG' => 'TGO',
        'TO' => 'TON', 'TT' => 'TTO', 'TN' => 'TUN', 'TR' => 'TUR', 'TM' => 'TKM',
        'TV' => 'TUV', 'UG' => 'UGA', 'UA' => 'UKR', 'AE' => 'ARE', 'GB' => 'GBR',
        'US' => 'USA', 'UY' => 'URY', 'UZ' => 'UZB', 'VU' => 'VUT', 'VE' => 'VEN',
        'VN' => 'VNM', 'YE' => 'YEM', 'ZM' => 'ZMB', 'ZW' => 'ZWE',
        'XK' => 'XKX', 'HK' => 'HKG', 'MO' => 'MAC', 'PS' => 'PSE', 'CW' => 'CUW',
        'SX' => 'SXM', 'BQ' => 'BES', 'AW' => 'ABW',
    ];

    /**
     * @param  Xs2OrderAttendee|SbOrderAttendee|array<string, mixed>  $attendee
     * @param  list<string>  $keys
     */
    private function firstString(object|array $attendee, array $keys): ?string
    {
        foreach ($this->valueBags($attendee) as $bag) {
            foreach ($keys as $key) {
                if (! array_key_exists($key, $bag)) {
                    continue;
                }

                $string = $this->nullableString($bag[$key]);
                if ($string !== null) {
                    return $string;
                }
            }
        }

        return null;
    }

    /**
     * Column values win over raw_payload aliases.
     *
     * @param  Xs2OrderAttendee|SbOrderAttendee|array<string, mixed>  $attendee
     * @return list<array<string, mixed>>
     */
    private function valueBags(object|array $attendee): array
    {
        if (is_array($attendee)) {
            return [$attendee];
        }

        $columns = array_filter([
            'first_name' => $attendee->first_name ?? null,
            'firstname' => $attendee->first_name ?? null,
            'last_name' => $attendee->last_name ?? null,
            'lastname' => $attendee->last_name ?? null,
            'dob' => $attendee->dob ?? null,
            'date_of_birth' => $attendee->dob ?? null,
            'nationality' => $attendee->nationality ?? null,
            'country_of_residence' => $attendee->nationality ?? null,
            'province' => $attendee->province ?? null,
            'state' => $attendee->province ?? null,
            'email' => $attendee->email ?? null,
            'contact_email' => $attendee->email ?? null,
            'phone' => $attendee->phone ?? null,
            'contact_phone' => $attendee->phone ?? null,
            'mobile' => $attendee->phone ?? null,
            'passport' => $attendee->passport ?? null,
            'passport_number' => $attendee->passport ?? null,
            'gender' => $attendee->gender ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $bags = [$columns];
        $raw = $this->rawPayload($attendee);
        if ($raw !== []) {
            $bags[] = $raw;
        }

        return $bags;
    }

    /**
     * @param  Xs2OrderAttendee|SbOrderAttendee|array<string, mixed>  $attendee
     * @return array<string, mixed>
     */
    private function rawPayload(object|array $attendee): array
    {
        if (is_array($attendee)) {
            return $attendee;
        }

        return is_array($attendee->raw_payload ?? null) ? $attendee->raw_payload : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
