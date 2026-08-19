<?php

namespace App\Services\Xs2;

use App\Jobs\SyncXs2EventsJob;
use App\Models\Xs2SyncState;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Throwable;

class Xs2SyncManagementService
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly CacheRepository $cache,
    ) {}

    /** @return Collection<int, array{sport: string, state: Xs2SyncState|null}> */
    public function statuses(): Collection
    {
        $sports = $this->configuredSports();
        $states = Xs2SyncState::query()
            ->whereIn('resource', array_map(fn (string $sport) => "events:{$sport}", $sports))
            ->get()
            ->keyBy('resource');

        return collect($sports)->map(fn (string $sport) => [
            'sport' => $sport,
            'state' => $states->get("events:{$sport}"),
        ])->values();
    }

    public function queue(string $sport, bool $full): bool
    {
        $job = new SyncXs2EventsJob($sport, $full);
        $uniqueLock = new UniqueLock($this->cache);

        if (! $uniqueLock->acquire($job)) {
            return false;
        }

        try {
            // The lock is acquired explicitly so the controller can report a
            // duplicate without dispatching a second job.
            $this->dispatcher->dispatch($job);
        } catch (Throwable $exception) {
            $uniqueLock->release($job);

            throw $exception;
        }

        return true;
    }

    /** @return list<string> */
    private function configuredSports(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $sport) => is_string($sport) ? trim($sport) : '',
            config('services.xs2.sports', []),
        ))));
    }
}
