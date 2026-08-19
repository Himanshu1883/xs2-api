<?php

namespace App\Services\Admin;

class QueueWorkerDetectionService
{
    /**
     * @return array{
     *     supported: bool,
     *     detected: bool,
     *     process_count: int,
     *     processes: list<array{pid: int, queue: string|null, command: string}>
     * }
     */
    public function detect(): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return [
                'supported' => false,
                'detected' => false,
                'process_count' => 0,
                'processes' => [],
            ];
        }

        $processes = $this->scanProcesses();

        return [
            'supported' => true,
            'detected' => $processes !== [],
            'process_count' => count($processes),
            'processes' => $processes,
        ];
    }

    /**
     * @return list<array{pid: int, queue: string|null, command: string}>
     */
    private function scanProcesses(): array
    {
        $output = shell_exec('ps -eo pid=,args= 2>/dev/null | grep "[a]rtisan queue:work"') ?? '';
        if (trim($output) === '') {
            return [];
        }

        $processes = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (! preg_match('/^\s*(\d+)\s+(.+)$/', $line, $matches)) {
                continue;
            }

            $command = trim($matches[2]);
            $queue = null;
            if (preg_match('/--queue(?:=|\s+)([^\s]+)/', $command, $queueMatch)) {
                $queue = trim($queueMatch[1], '\'"');
            }

            $processes[] = [
                'pid' => (int) $matches[1],
                'queue' => $queue,
                'command' => $command,
            ];
        }

        return $processes;
    }
}
