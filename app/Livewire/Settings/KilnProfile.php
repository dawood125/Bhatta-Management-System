<?php

namespace App\Livewire\Settings;

use App\Actions\Settings\UpdateKilnProfile;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\Kiln;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The kiln's own details (owner only): names, address, contact and logo.
 */
class KilnProfile extends Component
{
    use RunsActions, WithFileUploads;

    /** @var array<string, string|null> */
    public array $form = [];

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public bool $removeLogo = false;

    public function mount(): void
    {
        $this->authorize(Permission::KilnProfileManage->value);
        $this->fillForm($this->kiln());
    }

    public function updatedLogo(): void
    {
        $this->removeLogo = false;
        $this->resetErrorBag('form.logo');
        $this->validateOnly('logo', ['logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024']], [], ['logo' => __('Logo')]);
    }

    public function save(UpdateKilnProfile $updateKilnProfile): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $kiln = $this->kiln();

        $this->runAction(fn () => $updateKilnProfile->handle($actor, $kiln, $this->form, $this->logo, $this->removeLogo), 'form');

        $this->logo = null;
        $this->removeLogo = false;
        $this->fillForm($kiln->fresh() ?? $kiln);

        // Reload so the sidebar shows the new name and logo.
        session()->flash('toast', ['type' => 'success', 'message' => __('Kiln profile saved.')]);
        $this->redirectRoute('settings.kiln', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.settings.kiln-profile', ['kiln' => $this->kiln()])->title(__('Kiln profile'));
    }

    private function kiln(): Kiln
    {
        return app(CurrentKiln::class)->get();
    }

    private function fillForm(Kiln $kiln): void
    {
        $this->form = [
            'name' => $kiln->name,
            'name_ur' => $kiln->name_ur,
            'address' => $kiln->address,
            'address_ur' => $kiln->address_ur,
            'phone' => $kiln->phone,
            'email' => $kiln->email,
        ];
    }
}
