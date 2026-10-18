<?php

namespace App\Livewire\Dev;

use App\Livewire\Concerns\RunsActions;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Design-system preview (local/testing only). Used to review components in English and Urdu.
 */
class ComponentGallery extends Component
{
    use RunsActions;

    public bool $showDemoModal = false;

    public string $sample = '';

    public function demoToast(string $type): void
    {
        $this->toast(match ($type) {
            'error' => __('Something went wrong. Nothing was saved.'),
            'warning' => __('Please check the highlighted fields.'),
            'info' => __('This is an information message.'),
            default => __('Changes saved.'),
        }, $type);
    }

    public function demoValidation(): void
    {
        $this->validate(['sample' => ['required', 'min:3']], [], ['sample' => __('Sample field')]);
    }

    public function render(): View
    {
        return view('livewire.dev.component-gallery')->title(__('Components'));
    }
}
