<?php

namespace App\Livewire\Concerns;

use App\Actions\Materials\ReverseMaterialEntry;
use App\Enums\Permission;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The "Reverse" dialog of the material screens (purchases, usage, opening stock, shortages).
 * Use with RunsActions; render <x-materials.reverse-modal /> in the view.
 */
trait ReversesMaterialEntries
{
    public bool $showReverseForm = false;

    public ?int $reversingId = null;

    /** @var array<string, string> */
    public array $reverseForm = ['reason' => ''];

    public string $reverseKey = '';

    public function confirmReverse(int $entryId): void
    {
        $this->authorize(Permission::TransactionsReverse->value);
        $entry = $this->findMaterialEntry($entryId);
        $this->resetErrorBag();
        $this->reversingId = $entry->id;
        $this->reverseForm = ['reason' => ''];
        $this->reverseKey = (string) Str::uuid();
        $this->showReverseForm = true;
    }

    public function reverse(ReverseMaterialEntry $reverseEntry): void
    {
        $entry = $this->findMaterialEntry((int) $this->reversingId);

        /** @var User $actor */
        $actor = auth()->user();
        $reversal = $this->runAction(fn () => $reverseEntry->handle($actor, $entry, $this->reverseForm, $this->reverseKey), 'reverseForm');

        $this->showReverseForm = false;
        $this->toast(__(':number reversed by :reversal.', ['number' => $entry->number, 'reversal' => $reversal->number]));
        $this->afterReverse();
    }

    /** Number of the entry in the open dialog. */
    public function reversingNumber(): ?string
    {
        return $this->reversingId !== null ? JournalEntry::query()->whereKey($this->reversingId)->value('number') : null;
    }

    /** Refresh what the screen shows after a reversal. */
    protected function afterReverse(): void {}

    private function findMaterialEntry(int $id): JournalEntry
    {
        $entry = JournalEntry::query()->findOrFail($id);
        abort_unless($entry->type->movesMaterialStock(), 404);

        return $entry;
    }
}
