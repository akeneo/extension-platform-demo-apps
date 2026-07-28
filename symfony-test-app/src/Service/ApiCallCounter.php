<?php

namespace App\Service;

/**
 * Counts outbound HTTP calls (Akeneo API + fake price/stock services) made
 * while processing a single sync attempt. Messenger's async worker runs
 * messages strictly sequentially (no concurrency configured), so a plain
 * reset-before/read-after counter is safe here — there's only ever one
 * attempt being processed at a time in this process.
 */
class ApiCallCounter
{
    private int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function reset(): void
    {
        $this->count = 0;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
