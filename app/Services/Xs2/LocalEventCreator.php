<?php

namespace App\Services\Xs2;

use App\Models\MatchInfo;
use App\Models\Xs2Event;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class LocalEventCreator
{
    /**
     * @param  array{category_id?: int, tournament_id?: int, home_team_id?: int, away_team_id?: int, city_id?: int, venue_id?: int}|int|null  $options
     */
    public function findOrCreate(Xs2Event $xs2Event, array|int|null $options = [], ?int $legacyTournamentId = null): MatchInfo
    {
        $options = $this->normalizeOptions($options, $legacyTournamentId);
        $columns = $this->matchInfoColumns();
        $this->requireBaseColumns($columns);
        $attributes = $this->baseAttributes($xs2Event);
        [$referenceAttributes, $references] = $this->referenceAttributes($options, $columns);
        $attributes = [...$attributes, ...$referenceAttributes];

        $this->validateTournamentCategoryPair($references);
        $this->ensureRequiredColumnsCanBeWritten($columns, $attributes);

        return DB::transaction(function () use ($xs2Event, $attributes): MatchInfo {
            $candidate = $this->duplicateQuery($xs2Event)->lockForUpdate()->first();
            if ($candidate) {
                return $candidate;
            }

            return MatchInfo::query()->create($attributes);
        }, 3);
    }

    /**
     * @param  array<string, mixed>|int|null  $options
     * @return array<string, mixed>
     */
    private function normalizeOptions(array|int|null $options, ?int $legacyTournamentId): array
    {
        if (is_array($options)) {
            return $options;
        }

        return array_filter([
            'category_id' => $options,
            'tournament_id' => $legacyTournamentId,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, array<string, mixed>> */
    private function matchInfoColumns(): array
    {
        if (! Schema::hasTable('match_info')) {
            $this->validation([
                'match_info' => ['The local event catalog is unavailable.'],
            ]);
        }

        $columns = [];
        foreach (Schema::getColumns('match_info') as $column) {
            $columns[$column['name']] = $column;
        }

        return $columns;
    }

    /** @param array<string, array<string, mixed>> $columns */
    private function requireBaseColumns(array $columns): void
    {
        $missing = array_values(array_filter(
            ['match_name', 'match_date', 'xs2event_id'],
            fn (string $column): bool => ! isset($columns[$column]),
        ));

        if ($missing !== []) {
            $this->validation([
                'match_info' => ['The local event catalog cannot safely store the required XS2 event fields: '.implode(', ', $missing).'.'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function baseAttributes(Xs2Event $xs2Event): array
    {
        $errors = [];
        $start = $this->startDate($xs2Event);
        $name = is_string($xs2Event->event_name) ? trim($xs2Event->event_name) : '';
        $externalId = is_string($xs2Event->external_event_id) ? trim($xs2Event->external_event_id) : '';

        if (! $start) {
            $errors['date_start_local'] = ['An XS2 start date is required before a local event can be created.'];
        }

        if ($name === '') {
            $errors['event_name'] = ['An XS2 event name is required before a local event can be created.'];
        }

        if ($externalId === '') {
            $errors['external_event_id'] = ['An XS2 event ID is required before a local event can be created.'];
        }

        if ($errors !== []) {
            $this->validation($errors);
        }

        return [
            'match_name' => $name,
            'match_date' => $start,
            'xs2event_id' => $externalId,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, array<string, mixed>>  $columns
     * @return array{0: array<string, int>, 1: array<string, array{table: string, row: array<string, mixed>, columns: list<string>}>}
     */
    private function referenceAttributes(array $options, array $columns): array
    {
        $definitions = [
            'home_team_id' => [
                'column' => 'team_1',
                'label' => 'home team',
                'tables' => [['name' => 'teams', 'id_columns' => ['id', 'team_id']]],
            ],
            'away_team_id' => [
                'column' => 'team_2',
                'label' => 'away team',
                'tables' => [['name' => 'teams', 'id_columns' => ['id', 'team_id']]],
            ],
            'city_id' => [
                'column' => 'city',
                'label' => 'city',
                'tables' => [['name' => 'cities', 'id_columns' => ['id', 'city_id']]],
            ],
            'venue_id' => [
                'column' => 'venue',
                'label' => 'venue',
                'tables' => [
                    ['name' => 'stadium', 'id_columns' => ['s_id', 'stadium_id', 'id']],
                    ['name' => 'stadiums', 'id_columns' => ['s_id', 'stadium_id', 'id']],
                ],
            ],
            'category_id' => [
                'column' => 'category',
                'label' => 'category',
                'tables' => [['name' => 'game_category', 'id_columns' => ['id', 'game_cat_id', 'category_id']]],
            ],
            'tournament_id' => [
                'column' => 'tournament',
                'label' => 'tournament',
                'tables' => [['name' => 'tournament', 'id_columns' => ['t_id', 'id', 'tournament_id']]],
            ],
        ];

        $attributes = [];
        $resolved = [];
        $errors = [];

        foreach ($definitions as $field => $definition) {
            $column = $definition['column'];
            $id = $this->optionId($options, $field, $errors);

            if (! isset($columns[$column])) {
                if ($id !== null) {
                    $errors[$field] = ["The local event catalog does not expose a {$definition['label']} reference."];
                }

                continue;
            }

            if ($id === null) {
                if (! ($columns[$column]['nullable'] ?? false)) {
                    $errors[$field] = ["A verified local {$definition['label']} ID is required to create this event."];
                }

                continue;
            }

            $reference = $this->findReference($definition['tables'], $id);
            if (! $reference) {
                $errors[$field] = ["The selected local {$definition['label']} does not exist in the legacy catalog."];

                continue;
            }

            $attributes[$column] = $id;
            $resolved[$field] = $reference;
        }

        if ($errors !== []) {
            $this->validation($errors);
        }

        return [$attributes, $resolved];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, list<string>>  $errors
     */
    private function optionId(array $options, string $field, array &$errors): ?int
    {
        if (! array_key_exists($field, $options) || $options[$field] === null) {
            return null;
        }

        $value = $options[$field];
        if ((! is_int($value) && (! is_string($value) || ! ctype_digit($value))) || (int) $value < 1) {
            $errors[$field] = ['The selected local reference ID must be a positive integer.'];

            return null;
        }

        return (int) $value;
    }

    /**
     * @param  list<array{name: string, id_columns: list<string>}>  $tables
     * @return array{table: string, row: array<string, mixed>, columns: list<string>}|null
     */
    private function findReference(array $tables, int $id): ?array
    {
        foreach ($tables as $definition) {
            $table = $definition['name'];
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $idColumn = $this->firstColumn($columns, $definition['id_columns']);
            if (! $idColumn) {
                continue;
            }

            $row = DB::table($table)->where($idColumn, $id)->first();
            if ($row) {
                return [
                    'table' => $table,
                    'row' => (array) $row,
                    'columns' => $columns,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{table: string, row: array<string, mixed>, columns: list<string>}>  $references
     */
    private function validateTournamentCategoryPair(array $references): void
    {
        if (! isset($references['category_id'], $references['tournament_id'])) {
            return;
        }

        $tournament = $references['tournament_id'];
        $categoryColumn = $this->firstColumn($tournament['columns'], ['category', 'category_id', 'game_cat_id']);
        $tournamentCategory = $categoryColumn ? ($tournament['row'][$categoryColumn] ?? null) : null;
        $selectedCategory = $references['category_id']['row'];
        $selectedCategoryId = $this->firstColumn($references['category_id']['columns'], ['id', 'game_cat_id', 'category_id']);
        $selectedCategoryValue = $selectedCategoryId ? ($selectedCategory[$selectedCategoryId] ?? null) : null;

        if ($categoryColumn && is_numeric($tournamentCategory) && is_numeric($selectedCategoryValue)
            && (int) $tournamentCategory !== (int) $selectedCategoryValue) {
            $this->validation([
                'tournament_id' => ['The selected tournament does not belong to the selected category.'],
            ]);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $columns
     * @param  array<string, mixed>  $attributes
     */
    private function ensureRequiredColumnsCanBeWritten(array $columns, array $attributes): void
    {
        $unsupported = [];

        foreach ($columns as $name => $column) {
            if (array_key_exists($name, $attributes)
                || ($column['nullable'] ?? false)
                || ($column['default'] ?? null) !== null
                || ($column['auto_increment'] ?? false)
                || ($column['generation'] ?? null) !== null) {
                continue;
            }

            $unsupported[] = $name;
        }

        if ($unsupported !== []) {
            $this->validation([
                'match_info' => ['The local event catalog has required fields that cannot be populated safely: '.implode(', ', $unsupported).'.'],
            ]);
        }
    }

    private function duplicateQuery(Xs2Event $xs2Event): Builder
    {
        return MatchInfo::query()->where('xs2event_id', $xs2Event->external_event_id);
    }

    private function startDate(Xs2Event $xs2Event): ?Carbon
    {
        $value = $xs2Event->date_start_local;

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $columns @param list<string> $candidates */
    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string, list<string>> $messages */
    private function validation(array $messages): never
    {
        throw ValidationException::withMessages($messages);
    }
}
