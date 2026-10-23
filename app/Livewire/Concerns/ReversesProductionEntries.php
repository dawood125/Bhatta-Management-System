<?php

namespace App\Livewire\Concerns;

use App\Actions\Production\ReverseProductionEntry;
use App\Enums\Permission;
use App\Models\ProductionEntry;
use App\Models\User;

/**
 * The "Reverse" dialog of the production screens. Use with RunsActions; render
 * <x-production.reverse-modal :number="$this->reversingNumber()" /> in the view.
 */
trait ReversesProductionEntries
{
    public bool $showReverseForm = false;

    public ?int $reversingId = null;

    /** @var array<string, string> */
    public array $reverseForm = ['reason' => ''];

    public function confirmReverse(int $entryId): void
    {
        $this->authorize(Permission::ProductionAdjust->value);
        $entry = ProductionEntry::query()->findOrFail($entryId);
        $this->resetErrorBag();
        $this->reversingId = $entry->id;
        $this->reverseForm = ['reason' => ''];
        $this->showReverseForm = true;
    }

    public function reverse(ReverseProductionEntry $reverseEntry): void
    {
        $entry = ProductionEntry::query()->findOrFail((int) $this->reversingId);

        /** @var User $actor */
        $actor = auth()->user();
        $this->runAction(fn () => $reverseEntry->handle($actor, $entry, $this->reverseForm), 'reverseForm');

        $this->showReverseForm = false;
        $this->toast(__(':number reversed.', ['number' => $entry->number]));
        $this->afterReverse();
    }

    /** Number of the entry in the open dialog. */
    public function reversingNumber(): ?string
    {
        return $this->reversingId !== null ? ProductionEntry::query()->whereKey($this->reversingId)->value('number') : null;
    }

    /** Refresh what the screen shows after a reversal. */
    protected function afterReverse(): void {}
}
