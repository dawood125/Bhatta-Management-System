<?php

namespace App\Console\Commands;

use App\Accounting\LedgerVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ledger:verify')]
#[Description('Re-check every journal entry: balanced, one-sided lines, same kiln, reversals and opening balances')]
class VerifyLedger extends Command
{
    public function handle(LedgerVerifier $verifier): int
    {
        $problems = $verifier->verify();

        if ($problems === []) {
            $this->components->info('The ledger is consistent.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        $this->components->error(count($problems).' problem(s) found.');

        return self::FAILURE;
    }
}
