<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueFailedJobsService
{
    private function table(): string
    {
        return (string) config('queue.failed.table', 'failed_jobs');
    }

    public function available(): bool
    {
        return Schema::hasTable($this->table());
    }

    /**
     * @return array{
     *     available: bool,
     *     data: list<array<string, mixed>>,
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function list(int $page = 1, int $perPage = 20, ?string $queue = null): array
    {
        if (! $this->available()) {
            return [
                'available' => false,
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ];
        }

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $query = DB::table($this->table())->orderByDesc('failed_at');
        if ($queue !== null && $queue !== '') {
            $query->where('queue', $queue);
        }

        $total = (int) $query->count();
        $rows = $query
            ->forPage($page, $perPage)
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row->payload ?? ''), true);
            $exception = (string) ($row->exception ?? '');
            $exceptionLine = trim(strtok($exception, "\n") ?: '');

            $data[] = [
                'id' => (int) $row->id,
                'uuid' => (string) $row->uuid,
                'connection' => (string) ($row->connection ?? ''),
                'queue' => (string) ($row->queue ?? ''),
                'job_name' => $this->resolveJobName(is_array($payload) ? $payload : []),
                'exception_summary' => $exceptionLine !== '' ? mb_substr($exceptionLine, 0, 240) : null,
                'failed_at' => $row->failed_at,
            ];
        }

        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'available' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }

    /** @return array{retried: int, failed: list<string>} */
    public function retry(string $uuid): array
    {
        $this->assertAvailable();

        $row = DB::table($this->table())->where('uuid', $uuid)->first();
        if ($row === null) {
            throw new \InvalidArgumentException("Failed job “{$uuid}” was not found.");
        }

        $exitCode = Artisan::call('queue:retry', ['id' => [$uuid]]);

        return [
            'retried' => $exitCode === 0 ? 1 : 0,
            'failed' => $exitCode === 0 ? [] : [$uuid],
        ];
    }

    /** @return array{retried: int, failed: list<string>} */
    public function retryAll(?string $queue = null): array
    {
        $this->assertAvailable();

        $query = DB::table($this->table())->orderBy('id');
        if ($queue !== null && $queue !== '') {
            $query->where('queue', $queue);
        }

        $uuids = $query->pluck('uuid')->map(static fn ($uuid): string => (string) $uuid)->all();
        if ($uuids === []) {
            return ['retried' => 0, 'failed' => []];
        }

        $parameters = ['id' => $uuids];
        if ($queue !== null && $queue !== '') {
            $parameters['--queue'] = $queue;
        }

        $exitCode = Artisan::call('queue:retry', $parameters);

        return [
            'retried' => $exitCode === 0 ? count($uuids) : 0,
            'failed' => $exitCode === 0 ? [] : $uuids,
        ];
    }

    public function delete(string $uuid): bool
    {
        $this->assertAvailable();

        $deleted = (int) DB::table($this->table())->where('uuid', $uuid)->delete();

        if ($deleted === 0) {
            throw new \InvalidArgumentException("Failed job “{$uuid}” was not found.");
        }

        return true;
    }

    /** @param  array<string, mixed>  $payload */
    private function resolveJobName(array $payload): string
    {
        if (isset($payload['displayName']) && is_string($payload['displayName']) && $payload['displayName'] !== '') {
            return $payload['displayName'];
        }

        $command = $payload['data']['commandName'] ?? null;
        if (is_string($command) && $command !== '') {
            return class_basename($command);
        }

        return 'Unknown job';
    }

    private function assertAvailable(): void
    {
        if (! $this->available()) {
            throw new \RuntimeException('The failed_jobs table is missing. Run php artisan queue:failed-table && php artisan migrate.');
        }
    }
}
