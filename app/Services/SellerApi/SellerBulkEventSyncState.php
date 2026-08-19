<?php

namespace App\Services\SellerApi;

use App\Exceptions\Integrations\SellerApiRequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class SellerBulkEventSyncState
{
    private const PREFIX = 'seller-api:bulk-event-sync:';

    private const TTL_SECONDS = 86_400;

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    public static function create(int $tournamentId, string $environment, array $preview): array
    {
        $syncId = (string) Str::uuid();
        $state = [
            'sync_id' => $syncId,
            'status' => 'queued',
            'tournament_id' => $tournamentId,
            'tournament_name' => $preview['tournament_name'] ?? null,
            'environment' => $environment,
            'request_url' => $preview['request_urls'][$environment] ?? null,
            'catalog_tournament_id' => $preview['catalog_tournament_id'] ?? null,
            'progress' => [
                'current_page' => 0,
                'last_page' => null,
                'fetched' => 0,
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
            ],
            'result' => null,
            'message' => 'Waiting to start bulk sync.',
            'status_message' => 'Queued — preparing to fetch events from Seats Broker.',
            'kick_requested' => false,
            'kick_attempts' => 0,
            'debug' => null,
            'seller_api_debug' => null,
            'queued_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];

        self::put($syncId, $state);

        return $state;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $syncId): ?array
    {
        $state = Cache::get(self::PREFIX.$syncId);

        return is_array($state) ? $state : null;
    }

    public static function markRunning(string $syncId): void
    {
        self::patch($syncId, [
            'status' => 'running',
            'status_message' => 'Connecting to Seats Broker catalog…',
            'started_at' => now()->toIso8601String(),
        ]);
    }

    public static function shouldRunInline(string $syncId): bool
    {
        $state = self::get($syncId);
        if ($state === null) {
            return false;
        }

        if (! ($state['kick_requested'] ?? false)) {
            return false;
        }

        if (($state['status'] ?? null) !== 'queued') {
            return false;
        }

        if (isset($state['started_at'])) {
            return false;
        }

        $lastKick = $state['last_kick_at'] ?? null;
        if (is_string($lastKick) && $lastKick !== '') {
            $secondsSinceKick = now()->diffInSeconds(\Illuminate\Support\Carbon::parse($lastKick));
            if ($secondsSinceKick < 2) {
                return false;
            }
        }

        return ((int) ($state['kick_attempts'] ?? 0)) < 5;
    }

    public static function markKickRequested(string $syncId): void
    {
        self::patch($syncId, [
            'kick_requested' => true,
        ]);
    }

    public static function shouldStartBackgroundRun(string $syncId): bool
    {
        return self::shouldRunInline($syncId);
    }

    public static function recordKickAttempt(string $syncId): void
    {
        $state = self::get($syncId) ?? ['kick_attempts' => 0];

        self::patch($syncId, [
            'kick_attempts' => ((int) ($state['kick_attempts'] ?? 0)) + 1,
            'last_kick_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function updateProgress(string $syncId, array $summary, int $currentPage, int $lastPage): void
    {
        self::patch($syncId, [
            'status' => 'running',
            'status_message' => sprintf(
                'Processing catalog page %d%s… (%d fetched, %d created, %d skipped, %d failed)',
                $currentPage,
                $lastPage > 0 ? ' of '.$lastPage : '',
                (int) ($summary['fetched'] ?? 0),
                (int) ($summary['created'] ?? 0),
                (int) ($summary['skipped'] ?? 0),
                (int) ($summary['failed'] ?? 0),
            ),
            'progress' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'fetched' => (int) ($summary['fetched'] ?? 0),
                'created' => (int) ($summary['created'] ?? 0),
                'skipped' => (int) ($summary['skipped'] ?? 0),
                'failed' => (int) ($summary['failed'] ?? 0),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $sellerApiDebug
     * @return array<string, mixed>
     */
    public static function markCompleted(string $syncId, array $result, array $sellerApiDebug = []): array
    {
        $label = $result['tournament_name'] ?? 'tournament #'.($result['tournament_id'] ?? '?');
        $message = sprintf(
            'Bulk sync for %s: %d fetched, %d created, %d skipped, %d failed.',
            $label,
            $result['fetched'] ?? 0,
            $result['created'] ?? 0,
            $result['skipped'] ?? 0,
            $result['failed'] ?? 0,
        );

        if ($sellerApiDebug !== []) {
            $result['seller_api_debug'] = $sellerApiDebug;
        }

        return self::patch($syncId, [
            'status' => 'completed',
            'message' => $message,
            'status_message' => 'Bulk sync completed successfully.',
            'result' => $result,
            'seller_api_debug' => $sellerApiDebug !== [] ? $sellerApiDebug : null,
            'progress' => [
                'current_page' => null,
                'last_page' => null,
                'fetched' => (int) ($result['fetched'] ?? 0),
                'created' => (int) ($result['created'] ?? 0),
                'skipped' => (int) ($result['skipped'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
            ],
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $sellerApiDebug
     * @return array<string, mixed>
     */
    public static function markFailed(
        string $syncId,
        \Throwable $exception,
        array $sellerApiDebug,
        SellerEventImportService $import,
        int $tournamentId,
        string $environment,
    ): array {
        $debug = $exception instanceof SellerApiRequestException ? $exception->context : [];

        if ($exception instanceof SellerApiRequestException && $exception->status !== null) {
            $debug['http_status'] = $exception->status;
        }

        try {
            $preview = $import->previewBulkSync($tournamentId);
            $debug = [
                'environment' => $environment,
                'request_url' => $preview['request_urls'][$environment] ?? null,
                ...$debug,
            ];
        } catch (\Throwable) {
            $debug = [
                'environment' => $environment,
                ...$debug,
            ];
        }

        $cause = trim($exception->getMessage());
        if ($cause !== '') {
            $debug['cause'] = $cause;
        }

        $debug['exception'] = $exception::class;

        if (config('app.debug')) {
            $debug['file'] = $exception->getFile();
            $debug['line'] = $exception->getLine();
        }

        $message = $cause !== ''
            ? $cause
            : 'Seatsbroker bulk sync could not be completed.';

        if (str_contains(strtolower($message), 'maximum execution time')) {
            $message = 'Bulk sync timed out. Ensure the queue worker is running and retry — sync runs in the background with a longer timeout.';
        }

        return self::patch($syncId, [
            'status' => 'failed',
            'message' => $message,
            'status_message' => 'Bulk sync failed.',
            'debug' => array_filter(
                $debug,
                static fn (mixed $value): bool => $value !== null && $value !== '',
            ),
            'seller_api_debug' => $sellerApiDebug !== [] ? $sellerApiDebug : null,
            'failed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private static function patch(string $syncId, array $patch): array
    {
        $current = self::get($syncId) ?? ['sync_id' => $syncId];
        $next = [...$current, ...$patch, 'updated_at' => now()->toIso8601String()];
        self::put($syncId, $next);

        return $next;
    }

    /** @param  array<string, mixed>  $state */
    private static function put(string $syncId, array $state): void
    {
        Cache::put(self::PREFIX.$syncId, $state, self::TTL_SECONDS);
    }
}
