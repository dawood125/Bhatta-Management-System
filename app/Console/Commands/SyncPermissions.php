<?php

namespace App\Console\Commands;

use App\Support\Access\PermissionSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('permissions:sync')]
#[Description('Create/update all permissions and default roles from the code definitions')]
class SyncPermissions extends Command
{
    public function handle(PermissionSynchronizer $synchronizer): int
    {
        $synchronizer->sync();
        $this->components->info('Permissions and roles are up to date.');

        return self::SUCCESS;
    }
}
