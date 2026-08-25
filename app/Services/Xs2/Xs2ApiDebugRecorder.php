<?php

namespace App\Services\Xs2;

use Illuminate\Support\Facades\Cache;

class Xs2ApiDebugRecorder
{
    private bool $enabled = false;

    /** @var list<array<string, mixed>> */
    private array $interactions = [];

    public function enable(): void
    {
        $this->enabled = true;
        $this->interactions = [];
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** @param array<string, mixed> $interaction */
    public function record(array $interaction): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->interactions[] = $interaction;
    }

    /** @return list<array<string, mixed>> */
    public function interactions(): array
    {
        return $this->interactions;
    }

    /** @return list<array<string, mixed>> */
    public function flush(): array
    {
        $interactions = $this->interactions;
        $this->interactions = [];
        $this->enabled = false;

        return $interactions;
    }

    /**
     * Persist the last recorded debug payload for admin inspection.
     *
     * @param  list<array<string, mixed>>  $interactions
     * @return array<string, mixed>
     */
    public function persist(array $interactions, ?int $mappingId = null, ?string $externalEventId = null): array
    {
        $payload = [
            'scope' => $mappingId !== null ? 'event' : 'global',
            'mapping_id' => $mappingId,
            'external_event_id' => $externalEventId,
            'recorded_at' => now()->toIso8601String(),
            'interactions' => $interactions,
        ];

        Cache::put($this->globalCacheKey(), $payload, now()->addDay());

        if ($mappingId !== null) {
            Cache::put($this->mappingCacheKey($mappingId), $payload, now()->addDay());
        }

        return $payload;
    }

    /**
     * Prefer the last call for this mapping; fall back to the global XS2 API last call.
     *
     * @return array{payload: array<string, mixed>|null, scope: 'event'|'global'|'none'}
     */
    public function lastForMapping(?int $mappingId): array
    {
        if ($mappingId !== null) {
            $eventPayload = Cache::get($this->mappingCacheKey($mappingId));
            if (is_array($eventPayload)) {
                return ['payload' => $eventPayload, 'scope' => 'event'];
            }
        }

        $globalPayload = Cache::get($this->globalCacheKey());
        if (is_array($globalPayload)) {
            return ['payload' => $globalPayload, 'scope' => 'global'];
        }

        return ['payload' => null, 'scope' => 'none'];
    }

    private function globalCacheKey(): string
    {
        return 'xs2-api-debug:last';
    }

    private function mappingCacheKey(int $mappingId): string
    {
        return 'xs2-api-debug:mapping:'.$mappingId;
    }

    /**
     * Append a batch of XS2 API interactions recorded during a cron/queue sync.
     *
     * @param  list<array<string, mixed>>  $interactions
     * @return array<string, mixed>
     */
    public function appendCronInteractions(
        array $interactions,
        string $source,
        ?string $taskId = null,
        ?string $externalEventId = null,
    ): array {
        if ($interactions === []) {
            return [];
        }

        $entry = [
            'source' => $source,
            'task_id' => $taskId,
            'external_event_id' => $externalEventId,
            'recorded_at' => now()->toIso8601String(),
            'interactions' => $interactions,
        ];

        /** @var list<array<string, mixed>> $existing */
        $existing = Cache::get($this->cronLogCacheKey(), []);
        if (! is_array($existing)) {
            $existing = [];
        }

        $existing[] = $entry;
        $existing = array_slice($existing, -25);
        Cache::put($this->cronLogCacheKey(), $existing, now()->addHours(6));

        try {
            app(\App\Services\Admin\CronExecutionLogService::class)->appendInventoryApiRequests(
                $interactions,
                $externalEventId,
                $taskId,
            );
        } catch (\Throwable) {
            // Cron execution logs are optional during migrations or tests.
        }

        return $entry;
    }

    /** @return list<array<string, mixed>> */
    public function cronLog(): array
    {
        $log = Cache::get($this->cronLogCacheKey(), []);

        return is_array($log) ? $log : [];
    }

    /** @return array<string, mixed>|null */
    public function lastGlobal(): ?array
    {
        $payload = Cache::get($this->globalCacheKey());

        return is_array($payload) ? $payload : null;
    }

    private function cronLogCacheKey(): string
    {
        return 'xs2-api-debug:cron-log';
    }
}
