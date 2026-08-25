<?php

namespace Tests\Unit;

use App\Services\Xs2\Xs2GuestDataPayloadBuilder;
use Tests\TestCase;

class Xs2GuestDataPayloadBuilderTest extends TestCase
{
    private const TICKET_ID = 'a5cf8e7b39c14aeba4de30a8f67b9e23_tck';

    public function test_build_matches_xs2_guest_data_contract_and_maps_stored_fields(): void
    {
        $payload = app(Xs2GuestDataPayloadBuilder::class)->build(
            self::TICKET_ID,
            [[
                'first_name' => 'John',
                'last_name' => 'Doe',
                'passport' => 'ABC123456',
                'email' => 'user@example.com',
                'phone' => '+31123456789',
                'dob' => '1991-01-30',
                'gender' => 'Male',
                'nationality' => 'nld',
                'street_name' => 'Hereweg 95',
                'city' => 'Groningen',
                'zip' => '9721AA',
            ]],
        );

        $this->assertSame(['items'], array_keys($payload));
        $this->assertCount(1, $payload['items']);
        $this->assertSame(self::TICKET_ID, $payload['items'][0]['ticket_id']);
        $this->assertSame(Xs2GuestDataPayloadBuilder::GUEST_FIELD_KEYS, array_keys($payload['items'][0]['guests'][0]));
        $this->assertSame([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'passport_number' => 'ABC123456',
            'contact_email' => 'user@example.com',
            'contact_phone' => '+31123456789',
            'lead_guest' => true,
            'date_of_birth' => '1991-01-30',
            'gender' => 'male',
            'country_of_residence' => 'NLD',
            'street_name' => 'Hereweg 95',
            'city' => 'Groningen',
            'zip' => '9721AA',
            'guest_id' => null,
        ], $payload['items'][0]['guests'][0]);
    }

    public function test_build_sends_nulls_for_empty_optional_fields_and_merges_existing_guest_id(): void
    {
        $payload = app(Xs2GuestDataPayloadBuilder::class)->build(
            self::TICKET_ID,
            [
                [
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'email' => 'jane@example.com',
                    'gender' => 'other',
                ],
                [
                    'first_name' => 'Alex',
                    'last_name' => 'Doe',
                    'passport_number' => 'XYZ999',
                ],
            ],
            [
                ['guest_id' => 'guest-1_gst'],
                ['guest_id' => ['value' => 'guest-2_gst']],
            ],
        );

        $first = $payload['items'][0]['guests'][0];
        $second = $payload['items'][0]['guests'][1];

        $this->assertTrue($first['lead_guest']);
        $this->assertFalse($second['lead_guest']);
        $this->assertSame('guest-1_gst', $first['guest_id']);
        $this->assertSame('guest-2_gst', $second['guest_id']);
        $this->assertSame('unknown', $first['gender']);
        $this->assertNull($first['passport_number']);
        $this->assertNull($first['street_name']);
        $this->assertArrayNotHasKey('conditions', $first);
        $this->assertArrayNotHasKey('reservation_id', $first);
        $this->assertArrayNotHasKey('ticket_id', $first);
        $this->assertArrayNotHasKey('additional_street_name', $first);
        $this->assertArrayNotHasKey('province', $first);
        $this->assertArrayNotHasKey('supported_team', $first);
        $this->assertSame(Xs2GuestDataPayloadBuilder::GUEST_FIELD_KEYS, array_keys($first));
        $this->assertSame(Xs2GuestDataPayloadBuilder::GUEST_FIELD_KEYS, array_keys($second));
    }

    public function test_street_name_concatenates_house_number_when_separate(): void
    {
        $payload = app(Xs2GuestDataPayloadBuilder::class)->build(
            self::TICKET_ID,
            [[
                'first_name' => 'Hans',
                'last_name' => 'Müller',
                'street_name' => 'Hauptstraße',
                'house_number' => '42',
            ]],
        );

        $guest = $payload['items'][0]['guests'][0];
        $this->assertSame('Hauptstraße 42', $guest['street_name']);
    }

    public function test_street_name_does_not_duplicate_house_number_already_present(): void
    {
        $payload = app(Xs2GuestDataPayloadBuilder::class)->build(
            self::TICKET_ID,
            [[
                'first_name' => 'Hans',
                'last_name' => 'Müller',
                'street_name' => 'Hauptstraße 42',
                'house_number' => '42',
            ]],
        );

        $guest = $payload['items'][0]['guests'][0];
        $this->assertSame('Hauptstraße 42', $guest['street_name']);
    }

    public function test_country_of_residence_converts_alpha2_to_alpha3(): void
    {
        $payload = app(Xs2GuestDataPayloadBuilder::class)->build(
            self::TICKET_ID,
            [[
                'first_name' => 'Pieter',
                'last_name' => 'de Vries',
                'nationality' => 'nl',
            ]],
        );

        $this->assertSame('NLD', $payload['items'][0]['guests'][0]['country_of_residence']);
    }

    public function test_country_of_residence_converts_country_name_to_alpha3(): void
    {
        $payload = app(Xs2GuestDataPayloadBuilder::class)->build(
            self::TICKET_ID,
            [[
                'first_name' => 'Carlos',
                'last_name' => 'Garcia',
                'nationality' => 'Spain',
            ]],
        );

        $this->assertSame('ESP', $payload['items'][0]['guests'][0]['country_of_residence']);
    }

    public function test_country_of_residence_uppercases_alpha3(): void
    {
        $payload = app(Xs2GuestDataPayloadBuilder::class)->build(
            self::TICKET_ID,
            [[
                'first_name' => 'Pieter',
                'last_name' => 'de Vries',
                'nationality' => 'nld',
            ]],
        );

        $this->assertSame('NLD', $payload['items'][0]['guests'][0]['country_of_residence']);
    }

    public function test_gender_normalizes_other_to_unknown(): void
    {
        $payload = app(Xs2GuestDataPayloadBuilder::class)->build(
            self::TICKET_ID,
            [[
                'first_name' => 'Sam',
                'last_name' => 'Taylor',
                'gender' => 'Other',
            ]],
        );

        $this->assertSame('unknown', $payload['items'][0]['guests'][0]['gender']);
    }

    public function test_only_official_xs2_fields_are_present_in_guest_output(): void
    {
        $officialFields = [
            'first_name', 'last_name', 'passport_number', 'contact_email', 'contact_phone',
            'lead_guest', 'date_of_birth', 'gender', 'country_of_residence',
            'street_name', 'city', 'zip', 'guest_id',
        ];

        $this->assertSame($officialFields, Xs2GuestDataPayloadBuilder::GUEST_FIELD_KEYS);

        $payload = app(Xs2GuestDataPayloadBuilder::class)->build(
            self::TICKET_ID,
            [['first_name' => 'Test', 'last_name' => 'User']],
        );

        $guestKeys = array_keys($payload['items'][0]['guests'][0]);
        $this->assertSame($officialFields, $guestKeys);

        $nonDocFields = ['additional_street_name', 'province', 'supported_team', 'conditions', 'reservation_id', 'ticket_id'];
        foreach ($nonDocFields as $field) {
            $this->assertArrayNotHasKey($field, $payload['items'][0]['guests'][0], "Non-doc field '{$field}' should not be in guest output");
        }
    }
}
