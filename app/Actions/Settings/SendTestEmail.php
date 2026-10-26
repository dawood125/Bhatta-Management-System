<?php

namespace App\Actions\Settings;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Mail\TestMail;
use App\Models\User;
use App\Support\Mail\MailSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sends a short email now (not through the queue) with the saved email settings, so the owner
 * sees at once whether they work, and the server's own error message if they do not.
 */
class SendTestEmail
{
    use ValidatesSettings;

    /**
     * @param  array<string, mixed>  $input  to
     */
    public function handle(User $actor, array $input): void
    {
        $this->authorizeSettings($actor, Permission::KilnProfileManage);

        $data = Validator::make($this->clean($input), ['to' => ['required', 'email', 'max:255']], [], ['to' => __('email address')])->validate();

        app(MailSettings::class)->apply();

        try {
            Mail::to($data['to'])->send(new TestMail($actor));
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['to' => __('The email could not be sent: :error', ['error' => Str::limit($e->getMessage(), 300)])]);
        }
    }
}
