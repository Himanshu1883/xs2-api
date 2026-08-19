<?php

namespace App\Services\SellerApi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist Seatsbrokers external catalog venues into the legacy master-data tables
 * used by /events/venues and XS2→Seatsbroker mapping (stadium, stadium_seats, stadium_details).
 *
 * GET /api/venues returns venue rows with nested blocks. Each block carries a seat-category
 * id and a full_block_name (the physical section/block). Category display names are not in
 * the payload, so missing stadium_seats rows are created from the block-name prefix.
 */
class SellerVenueCatalogSyncService
{
    public function __construct(private readonly SellerApiClient $client) {}

    /**
     * @return array{
     *     venues_seen:int,
     *     venues_created:int,
     *     venues_updated:int,
     *     categories_created:int,
     *     sections_created:int,
     *     sections_updated:int
     * }
     */
    public function sync(?int $perPage = null, ?string $environment = null): array
    {
        if (! Schema::hasTable('stadium') || ! Schema::hasTable('stadium_seats') || ! Schema::hasTable('stadium_details')) {
            throw new \RuntimeException('Legacy stadium / stadium_seats / stadium_details tables are required for Seatsbroker venue sync.');
        }

        $summary = [
            'venues_seen' => 0,
            'venues_created' => 0,
            'venues_updated' => 0,
            'categories_created' => 0,
            'sections_created' => 0,
            'sections_updated' => 0,
        ];

        $page = 1;
        $lastPage = 1;
        $perPage ??= (int) config('seller-api.catalog_per_page', 100);

        do {
            $response = $this->client->fetchVenuesPage($page, $perPage, [], $environment);
            $batch = data_get($response, 'data');
            if (! is_array($batch)) {
                throw new \RuntimeException('Seller API venues response is missing a data array.');
            }

            foreach (array_values(array_filter($batch, is_array(...))) as $venue) {
                $this->syncVenue($venue, $summary);
            }

            $lastPage = max(1, (int) data_get($response, 'meta.last_page', 1));
            $page++;
        } while ($page <= $lastPage);

        return $summary;
    }

    /**
     * Page the venues catalog until the given stadium id is found, then upsert it.
     *
     * @return array{
     *     found:bool,
     *     venues_seen:int,
     *     venues_created:int,
     *     venues_updated:int,
     *     categories_created:int,
     *     sections_created:int,
     *     sections_updated:int
     * }
     */
    public function syncVenueByStadiumId(int $stadiumId, ?int $perPage = null, ?string $environment = null): array
    {
        if ($stadiumId < 1) {
            throw new \InvalidArgumentException('Stadium id must be a positive integer.');
        }

        if (! Schema::hasTable('stadium') || ! Schema::hasTable('stadium_seats') || ! Schema::hasTable('stadium_details')) {
            throw new \RuntimeException('Legacy stadium / stadium_seats / stadium_details tables are required for Seatsbroker venue sync.');
        }

        $summary = [
            'found' => false,
            'venues_seen' => 0,
            'venues_created' => 0,
            'venues_updated' => 0,
            'categories_created' => 0,
            'sections_created' => 0,
            'sections_updated' => 0,
        ];

        $venue = $this->client->fetchVenueByStadiumId($stadiumId, $environment);
        if ($venue !== null) {
            $this->syncVenue($venue, $summary);
            $summary['found'] = true;

            return $summary;
        }

        $page = 1;
        $lastPage = 1;
        $perPage ??= (int) config('seller-api.catalog_per_page', 100);
        $maxPages = max(1, (int) config('seller-api.catalog_venue_lookup_max_pages', 15));

        do {
            $response = $this->client->fetchVenuesPage($page, $perPage, [], $environment);
            $batch = data_get($response, 'data');
            if (! is_array($batch)) {
                throw new \RuntimeException('Seller API venues response is missing a data array.');
            }

            foreach (array_values(array_filter($batch, is_array(...))) as $candidate) {
                $candidateId = filter_var($candidate['s_id'] ?? null, FILTER_VALIDATE_INT);
                if ($candidateId !== $stadiumId) {
                    continue;
                }

                $this->syncVenue($candidate, $summary);
                $summary['found'] = true;

                return $summary;
            }

            $lastPage = max(1, (int) data_get($response, 'meta.last_page', 1));
            $page++;
        } while ($page <= $lastPage && $page <= $maxPages);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $venue
     * @param  array<string, int>  $summary
     */
    public function syncVenue(array $venue, array &$summary): void
    {
        $stadiumId = filter_var($venue['s_id'] ?? null, FILTER_VALIDATE_INT);
        if ($stadiumId === false || $stadiumId < 1) {
            return;
        }

        $summary['venues_seen']++;
        $name = $this->nullableString($venue['name'] ?? null) ?? "Venue #{$stadiumId}";
        $image = $this->nullableString($venue['venue_image'] ?? null);
        $blocks = is_array($venue['blocks'] ?? null) ? $venue['blocks'] : [];

        $existing = DB::table('stadium')->where('s_id', $stadiumId)->first();
        if ($existing === null) {
            DB::table('stadium')->insert([
                's_id' => $stadiumId,
                'stadium_type' => 1,
                'stadium_image' => $image,
                'stadium_name' => $name,
                'country' => null,
                'city' => null,
                'width' => '',
                'height' => '',
                'main_team' => '',
                'map_code' => '',
                'status' => '1',
                'attendee_status' => '0',
                'create_date' => now()->format('Y-m-d H:i:s'),
                'stadium_name_ar' => '',
                'source_type' => '1boxoffice',
                'category' => '1',
            ]);
            $summary['venues_created']++;
        } else {
            $updates = [];
            if ($name !== '' && (string) ($existing->stadium_name ?? '') !== $name) {
                $updates['stadium_name'] = $name;
            }
            if ($image !== null && (string) ($existing->stadium_image ?? '') !== $image) {
                $updates['stadium_image'] = $image;
            }
            if ($updates !== []) {
                DB::table('stadium')->where('s_id', $stadiumId)->update($updates);
                $summary['venues_updated']++;
            }
        }

        /** @var array<int, array{name:string,color:?string}> $categories */
        $categories = [];
        /** @var list<array{id:int,stadium_id:int,full_block_name:string,block_id:string,category:?int,block_color:string}> $details */
        $details = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $detailId = filter_var($block['id'] ?? null, FILTER_VALIDATE_INT);
            if ($detailId === false || $detailId < 1) {
                continue;
            }

            $fullBlockName = $this->nullableString($block['full_block_name'] ?? null) ?? "block-{$detailId}";
            $color = $this->nullableString($block['block_color'] ?? null) ?? 'rgba(0,0,0,1)';
            $categoryId = filter_var($block['category'] ?? null, FILTER_VALIDATE_INT);
            $categoryId = $categoryId === false || $categoryId < 1 ? null : $categoryId;

            if ($categoryId !== null && ! isset($categories[$categoryId])) {
                $categories[$categoryId] = [
                    'name' => $this->categoryNameFromBlock($fullBlockName),
                    'color' => $color,
                ];
            }

            $details[] = [
                'id' => $detailId,
                'stadium_id' => $stadiumId,
                'full_block_name' => $fullBlockName,
                'block_id' => $this->blockIdFromFullName($fullBlockName),
                'category' => $categoryId,
                'block_color' => $color,
            ];
        }

        $summary['categories_created'] += $this->upsertCategories($categories);
        [$created, $updated] = $this->upsertDetails($details);
        $summary['sections_created'] += $created;
        $summary['sections_updated'] += $updated;
    }

    /**
     * @param  array<int, array{name:string,color:?string}>  $categories
     */
    private function upsertCategories(array $categories): int
    {
        if ($categories === []) {
            return 0;
        }

        $existing = DB::table('stadium_seats')
            ->whereIn('id', array_keys($categories))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $existingLookup = array_fill_keys($existing, true);
        $created = 0;
        $now = (string) time();

        foreach ($categories as $id => $category) {
            if (isset($existingLookup[$id])) {
                continue;
            }

            DB::table('stadium_seats')->insert([
                'id' => $id,
                'seat_category' => $category['name'],
                'category_color' => $category['color'],
                'status' => '1',
                'create_date' => $now,
                'event_type' => 'match',
                'source_type' => '1boxoffice',
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @param  list<array{id:int,stadium_id:int,full_block_name:string,block_id:string,category:?int,block_color:string}>  $details
     * @return array{0:int,1:int}
     */
    private function upsertDetails(array $details): array
    {
        if ($details === []) {
            return [0, 0];
        }

        $ids = array_column($details, 'id');
        $existing = DB::table('stadium_details')
            ->whereIn('id', $ids)
            ->get(['id', 'stadium_id', 'full_block_name', 'block_id', 'category', 'block_color'])
            ->keyBy(fn ($row): int => (int) $row->id);

        $created = 0;
        $updated = 0;
        $inserts = [];
        $updates = [];

        foreach ($details as $detail) {
            $current = $existing->get($detail['id']);
            if ($current === null) {
                $inserts[] = [
                    ...$detail,
                    'match_id' => null,
                    'active_color' => null,
                    'source_type' => '1boxoffice',
                ];
                $created++;

                continue;
            }

            if (
                (int) $current->stadium_id !== $detail['stadium_id']
                || (string) ($current->full_block_name ?? '') !== $detail['full_block_name']
                || (string) ($current->block_id ?? '') !== $detail['block_id']
                || (int) ($current->category ?? 0) !== (int) ($detail['category'] ?? 0)
                || (string) ($current->block_color ?? '') !== $detail['block_color']
            ) {
                $updates[] = $detail;
                $updated++;
            }
        }

        foreach (array_chunk($inserts, 250) as $chunk) {
            DB::table('stadium_details')->insert($chunk);
        }

        foreach ($updates as $detail) {
            DB::table('stadium_details')->where('id', $detail['id'])->update([
                'stadium_id' => $detail['stadium_id'],
                'full_block_name' => $detail['full_block_name'],
                'block_id' => $detail['block_id'],
                'category' => $detail['category'],
                'block_color' => $detail['block_color'],
            ]);
        }

        return [$created, $updated];
    }

    private function categoryNameFromBlock(string $fullBlockName): string
    {
        $prefix = str_contains($fullBlockName, '_')
            ? substr($fullBlockName, 0, (int) strpos($fullBlockName, '_'))
            : $fullBlockName;
        $prefix = trim(str_replace(['-', '_'], ' ', $prefix));

        return $prefix !== '' ? $prefix : $fullBlockName;
    }

    private function blockIdFromFullName(string $fullBlockName): string
    {
        if (! str_contains($fullBlockName, '_')) {
            return $fullBlockName;
        }

        $suffix = substr($fullBlockName, (int) strrpos($fullBlockName, '_') + 1);

        return $suffix !== '' ? $suffix : $fullBlockName;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
