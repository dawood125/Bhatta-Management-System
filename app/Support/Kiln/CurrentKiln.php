<?php

namespace App\Support\Kiln;

use App\Models\Kiln;

/**
 * Holds the kiln the current request (or job/command) is working in.
 *
 * Every kiln-owned model is filtered by this value. It is set by EnsureKilnContext for web
 * requests and must be set explicitly (via run()) in console commands and queued jobs.
 */
class CurrentKiln
{
    private ?Kiln $kiln = null;

    public function set(Kiln $kiln): void
    {
        $this->kiln = $kiln;
        setPermissionsTeamId($kiln->id);
    }

    public function forget(): void
    {
        $this->kiln = null;
        setPermissionsTeamId(null);
    }

    public function has(): bool
    {
        return $this->kiln !== null;
    }

    public function get(): Kiln
    {
        return $this->kiln ?? throw new MissingKilnContext;
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    public function idOrNull(): ?int
    {
        return $this->kiln?->id;
    }

    /**
     * Run a callback inside a kiln context and restore the previous context afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Kiln $kiln, callable $callback): mixed
    {
        $previous = $this->kiln;
        $this->set($kiln);

        try {
            return $callback();
        } finally {
            $previous ? $this->set($previous) : $this->forget();
        }
    }
}
