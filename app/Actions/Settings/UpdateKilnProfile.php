<?php

namespace App\Actions\Settings;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Models\Kiln;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The kiln's own details, used on screens now and on invoices later. Owner only.
 */
class UpdateKilnProfile
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input  name, name_ur, address, address_ur, phone, email
     */
    public function handle(User $actor, Kiln $kiln, array $input, ?UploadedFile $logo = null, bool $removeLogo = false): Kiln
    {
        $this->authorizeSettings($actor, Permission::KilnProfileManage);

        if ($kiln->id !== $this->kilnId()) {
            abort(404);
        }

        $input = $this->clean($input, ['name_ur', 'address', 'address_ur', 'phone', 'email']);
        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower($input['email']);
        }

        $data = Validator::make($input + ['logo' => $logo], [
            'name' => ['required', 'string', 'max:255'],
            'name_ur' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'address_ur' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
        ])->validate();

        $oldLogo = $kiln->logo_path;
        $newLogo = $logo?->store('kiln-logos', 'public');

        try {
            DB::transaction(function () use ($kiln, $data, $newLogo, $removeLogo): void {
                $kiln->fill([
                    'name' => $data['name'],
                    'name_ur' => $data['name_ur'],
                    'address' => $data['address'],
                    'address_ur' => $data['address_ur'],
                    'phone' => $data['phone'],
                    'email' => $data['email'],
                ]);

                if ($newLogo !== null && $newLogo !== false) {
                    $kiln->logo_path = $newLogo;
                } elseif ($removeLogo) {
                    $kiln->logo_path = null;
                }

                $kiln->save();
            });
        } catch (\Throwable $e) {
            // Saving failed: do not leave an orphaned upload behind.
            if (is_string($newLogo)) {
                Storage::disk('public')->delete($newLogo);
            }

            throw $e;
        }

        // Only remove the old file once the new state is safely stored.
        if ($oldLogo !== null && $oldLogo !== $kiln->logo_path) {
            Storage::disk('public')->delete($oldLogo);
        }

        return $kiln;
    }
}
