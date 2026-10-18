<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Kiln;
use App\Models\User;
use App\Support\Access\KilnAccess;
use App\Support\Access\PermissionSynchronizer;
use App\Support\Kiln\CurrentKiln;
use App\Support\Kiln\KilnDefaults;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('kiln:install
    {--kiln= : Kiln name}
    {--owner= : Owner full name}
    {--username= : Owner login username}
    {--email= : Owner email (optional)}
    {--password= : Owner password (prompted if omitted)}')]
#[Description('First-time setup: create the kiln and its owner account')]
class InstallKiln extends Command
{
    public function handle(PermissionSynchronizer $permissions, KilnAccess $access, CurrentKiln $currentKiln, KilnDefaults $defaults): int
    {
        $input = [
            'kiln' => $this->option('kiln') ?? text(__('Kiln name'), required: true),
            'owner' => $this->option('owner') ?? text(__('Owner full name'), required: true),
            'username' => strtolower((string) ($this->option('username') ?? text(__('Owner username'), required: true))),
            'email' => $this->option('email') ?: null,
            'password' => $this->option('password') ?? password(__('Owner password'), required: true),
        ];

        $validator = Validator::make($input, [
            'kiln' => ['required', 'string', 'max:255'],
            'owner' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::default()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $permissions->sync();

        $kiln = DB::transaction(function () use ($input, $access, $currentKiln): Kiln {
            $kiln = Kiln::query()->create(['name' => $input['kiln']]);

            return $currentKiln->run($kiln, function () use ($kiln, $input, $access): Kiln {
                $owner = User::query()->create([
                    'name' => $input['owner'],
                    'username' => $input['username'],
                    'email' => $input['email'],
                    'password' => $input['password'],
                ]);

                $access->grant($owner, $kiln, Role::Owner);

                return $kiln;
            });
        });

        $defaults->seed($kiln);

        $this->components->info(__('Kiln ":kiln" is ready. The owner can now log in.', ['kiln' => $kiln->name]));

        return self::SUCCESS;
    }
}
