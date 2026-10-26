<?php

namespace App\Console\Commands;

use App\Support\SetupCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kiln:check')]
#[Description('Check that this installation is set up right (run after every deployment)')]
class CheckSetup extends Command
{
    public function handle(SetupCheck $check): int
    {
        $results = $check->run();
        $marks = [SetupCheck::OK => '<fg=green>✓ ok</>', SetupCheck::WARN => '<fg=yellow>! check</>', SetupCheck::FAIL => '<fg=red>✗ fix</>'];

        $this->table(['', 'Check', 'Result', ''], array_map(fn (array $r) => [$r['group'], $r['check'], $marks[$r['status']], $r['detail']], $results));

        $counts = array_count_values(array_column($results, 'status'));
        $summary = sprintf('%d ok, %d to check, %d to fix.', $counts[SetupCheck::OK] ?? 0, $counts[SetupCheck::WARN] ?? 0, $counts[SetupCheck::FAIL] ?? 0);

        if ($check->hasFailures()) {
            $this->components->error($summary);

            return self::FAILURE;
        }

        $this->components->info($summary);

        return self::SUCCESS;
    }
}
