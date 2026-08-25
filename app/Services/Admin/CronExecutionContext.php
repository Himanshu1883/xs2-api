<?php

namespace App\Services\Admin;

/**
 * Tracks the active cron execution log for the current PHP process.
 */
class CronExecutionContext
{
    private ?int $activeLogId = null;

    private ?string $cronJobId = null;

    public function set(int $logId, string $cronJobId): void
    {
        $this->activeLogId = $logId;
        $this->cronJobId = $cronJobId;
    }

    public function clear(): void
    {
        $this->activeLogId = null;
        $this->cronJobId = null;
    }

    public function activeLogId(): ?int
    {
        return $this->activeLogId;
    }

    public function cronJobId(): ?string
    {
        return $this->cronJobId;
    }

    public function hasActiveLog(): bool
    {
        return $this->activeLogId !== null && $this->activeLogId > 0;
    }
}
