<?php

namespace App\Livewire\Profile;

use App\Actions\Users\ChangeOwnPassword;
use App\Actions\Users\UpdateOwnProfile;
use App\Livewire\Concerns\RunsActions;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ProfileEdit extends Component
{
    use RunsActions;

    /** @var array<string, string|null> */
    public array $profile = [];

    /** @var array<string, string> */
    public array $passwordForm = ['current_password' => '', 'password' => '', 'password_confirmation' => ''];

    public function mount(): void
    {
        $user = $this->user();
        $this->profile = [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
        ];
    }

    public function saveProfile(UpdateOwnProfile $updateOwnProfile): void
    {
        $user = $this->user();
        $localeChanged = $this->profile['locale'] !== $user->locale;

        $this->runAction(fn () => $updateOwnProfile->handle($user, $this->profile), 'profile');

        if ($localeChanged) {
            // Reload so the whole page (direction, font, menus) switches language.
            session()->flash('toast', ['type' => 'success', 'message' => __('Profile saved.', [], $user->locale)]);
            $this->redirectRoute('profile.edit');

            return;
        }

        $this->toast(__('Profile saved.'));
    }

    public function changePassword(ChangeOwnPassword $changeOwnPassword): void
    {
        $this->runAction(
            fn () => $changeOwnPassword->handle($this->user(), $this->passwordForm, session()->getId()),
            'passwordForm',
        );

        $this->passwordForm = ['current_password' => '', 'password' => '', 'password_confirmation' => ''];
        $this->toast(__('Password changed. Other devices have been logged out.'));
    }

    public function render(): View
    {
        return view('livewire.profile.profile-edit', ['user' => $this->user()])->title(__('My profile'));
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
