<?php

namespace App\Services\SellerApi;

use App\Jobs\SyncSellerBulkEventsJob;
use Illuminate\Support\Facades\Log;

final class SellerBulkEventSyncRunner
{
    public static function kick(string $syncId, int $tournamentId, string $environment): void
    {
        if (! SellerBulkEventSyncState::shouldRunInline($syncId)) {
            return;
        }

        SellerBulkEventSyncState::recordKickAttempt($syncId);

        if (app()->environment('testing')) {
            SyncSellerBulkEventsJob::dispatchSync($syncId, $tournamentId, $environment);

            return;
        }

        if (self::startDetachedShellProcess($syncId, $tournamentId, $environment)) {
            return;
        }

        Log::warning('Seller bulk sync background process could not be started.', [
            'sync_id' => $syncId,
            'tournament_id' => $tournamentId,
            'environment' => $environment,
        ]);
    }

    public static function runInline(string $syncId, int $tournamentId, string $environment): void
    {
        if (! SellerBulkEventSyncState::shouldRunInline($syncId)) {
            return;
        }

        SellerBulkEventSyncState::recordKickAttempt($syncId);
        SyncSellerBulkEventsJob::dispatchSync($syncId, $tournamentId, $environment);
    }

    private static function startDetachedShellProcess(string $syncId, int $tournamentId, string $environment): bool
    {
        $php = self::phpBinary();
        $artisan = base_path('artisan');
        $logFile = storage_path('logs/bulk-sync-'.preg_replace('/[^a-zA-Z0-9_-]+/', '-', $syncId).'.log');

        if (PHP_OS_FAMILY === 'Windows') {
            $command = sprintf(
                'start /B "" %s %s seller-api:process-bulk-sync %s %d %s >> %s 2>&1',
                escapeshellarg($php),
                escapeshellarg($artisan),
                escapeshellarg($syncId),
                $tournamentId,
                escapeshellarg($environment),
                escapeshellarg($logFile),
            );
        } else {
            $command = sprintf(
                '%s %s seller-api:process-bulk-sync %s %d %s >> %s 2>&1 &',
                escapeshellarg($php),
                escapeshellarg($artisan),
                escapeshellarg($syncId),
                $tournamentId,
                escapeshellarg($environment),
                escapeshellarg($logFile),
            );
        }

        try {
            exec($command);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Seller bulk sync shell process failed to start.', [
                'sync_id' => $syncId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private static function phpBinary(): string
    {
        if (defined('PHP_BINARY')) {
            $binary = PHP_BINARY;

            if (is_string($binary) && $binary !== '' && ! str_contains($binary, 'php-fpm')) {
                return $binary;
            }
        }

        $candidate = PHP_BINDIR.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        if (is_executable($candidate)) {
            return $candidate;
        }

        return 'php';
    }
}
