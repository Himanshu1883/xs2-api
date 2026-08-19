<?php

namespace App\Services\SellerApi;

class SellerApiDebugRecorder
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
}
