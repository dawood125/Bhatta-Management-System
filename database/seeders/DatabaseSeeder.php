<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Kiln;
use App\Models\User;
use App\Support\Access\KilnAccess;
use App\Support\Access\PermissionSynchronizer;
use App\Support\Kiln\CurrentKiln;
use App\Support\Kiln\KilnDefaults;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * DEMO DATA for local development only. Every record is clearly labelled "Demo".
 * Production kilns are created with `php artisan kiln:install`, never with this seeder.
 *
 * Demo logins (local only): owner / manager / munshi / dataentry / viewer, password: demo1234
 */
class DatabaseSeeder extends Seeder
{
    public function run(PermissionSynchronizer $permissions, KilnAccess $access, CurrentKiln $currentKiln, KilnDefaults $defaults): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder must never run in production.');
        }

        $permissions->sync();

        $kiln = Kiln::query()->firstOrCreate(
            ['name' => 'Demo Kiln (demo data)'],
            ['name_ur' => 'ڈیمو بھٹہ (نمونہ ڈیٹا)', 'address' => 'Demo address', 'is_active' => true],
        );

        $currentKiln->run($kiln, function () use ($kiln, $access): void {
            $people = [
                ['owner', 'Demo Owner', Role::Owner, 'en'],
                ['manager', 'Demo Manager', Role::Manager, 'en'],
                ['munshi', 'Demo Munshi', Role::Munshi, 'ur'],
                ['dataentry', 'Demo Data Entry', Role::DataEntry, 'ur'],
                ['viewer', 'Demo Viewer', Role::Viewer, 'en'],
            ];

            foreach ($people as [$username, $name, $role, $locale]) {
                $user = User::query()->firstOrCreate(
                    ['username' => $username],
                    ['name' => $name, 'locale' => $locale, 'password' => 'demo1234', 'is_active' => true],
                );
                $access->grant($user, $kiln, $role);
            }
        });

        $defaults->seed($kiln);
    }
}
