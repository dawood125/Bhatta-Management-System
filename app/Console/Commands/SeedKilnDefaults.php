<?php

namespace App\Console\Commands;

use App\Models\Kiln;
use App\Support\Kiln\KilnDefaults;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kiln:defaults {--kiln= : Only this kiln id (default: all kilns)}')]
#[Description('Add any missing default units, grades, stages and expense categories to kilns (never changes existing rows)')]
class SeedKilnDefaults extends Command
{
    public function handle(KilnDefaults $defaults): int
    {
        $kilns = Kiln::query()
            ->when($this->option('kiln'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')
            ->get();

        if ($kilns->isEmpty()) {
            $this->components->error('No kiln found.');

            return self::FAILURE;
        }

        foreach ($kilns as $kiln) {
            $defaults->seed($kiln);
            $this->components->info("Defaults checked for kiln #{$kiln->id} ({$kiln->name}).");
        }

        return self::SUCCESS;
    }
}
