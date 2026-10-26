<?php

namespace App\Livewire\Reports;

use App\Accounting\BooksLock;
use App\Actions\Accounting\CloseDay;
use App\Actions\Accounting\ReopenDay;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\DayClose;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Reports\CashDay;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Day close: each cash/bank account's day (opening, in, out, what the books say), the cash
 * counted against it, and "Close the day". The history shows every close; the owner can reopen
 * the last one.
 *
 * @property-read list<array{account: TreasuryAccount, opening: Money, in: Money, out: Money, closing: Money}> $rows Livewire computed property
 * @property-read Carbon|null $closedThrough Livewire computed property
 * @property-read DayClose|null $closeOfDay Livewire computed property
 * @property-read Collection<int, DayClose> $history Livewire computed property
 */
class DayCloseIndex extends Component
{
    use RunsActions;

    #[Url]
    public string $date = '';

    /** @var array<int|string, string> treasury account id => amount counted */
    public array $counts = [];

    public string $notes = '';

    public bool $showConfirm = false;

    public bool $showReopen = false;

    #[Locked]
    public ?int $reopenId = null;

    /** @var array<string, string> */
    public array $reopenForm = ['reason' => ''];

    public function mount(): void
    {
        $this->authorize(Permission::DaysClose->value);

        if ($this->day() === null) {
            $this->date = now()->format('Y-m-d');
        }
    }

    public function day(): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            return null;
        }

        $day = Carbon::createFromFormat('Y-m-d', $this->date);

        return $day !== null && $day->format('Y-m-d') === $this->date && $day->lte(now()) ? $day->startOfDay() : null;
    }

    /**
     * @return list<array{account: TreasuryAccount, opening: Money, in: Money, out: Money, closing: Money}>
     */
    #[Computed]
    public function rows(): array
    {
        $day = $this->day();

        return $day === null ? [] : app(CashDay::class)->forDate($day);
    }

    #[Computed]
    public function closedThrough(): ?Carbon
    {
        return app(BooksLock::class)->closedThrough();
    }

    /** The close saved for exactly this day (the latest one, if it was closed more than once). */
    #[Computed]
    public function closeOfDay(): ?DayClose
    {
        return $this->day() === null ? null : DayClose::query()
            ->with(['counts.treasuryAccount', 'closer', 'reopener'])
            ->where('close_date', $this->date)
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, DayClose>
     */
    #[Computed]
    public function history(): Collection
    {
        return DayClose::query()->with(['counts', 'closer', 'reopener'])->latest('close_date')->latest('id')->limit(30)->get();
    }

    /** Counted minus the books for an account, while the amount typed can be read. */
    public function difference(int $accountId, Money $expected): ?Money
    {
        $typed = trim((string) ($this->counts[$accountId] ?? ''));
        if ($typed === '') {
            return null;
        }

        try {
            return Money::fromRupees($typed)->subtract($expected);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function updatedDate(): void
    {
        $this->counts = [];
        $this->notes = '';
        $this->resetErrorBag();
        unset($this->rows, $this->closeOfDay);
    }

    public function confirmClose(): void
    {
        $this->resetErrorBag();
        $this->showConfirm = true;
    }

    public function closeDay(CloseDay $closeDay): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $this->runAction(fn () => $closeDay->handle($actor, ['close_date' => $this->date, 'notes' => $this->notes, 'counts' => $this->counts]));

        $this->showConfirm = false;
        $this->counts = [];
        $this->notes = '';
        unset($this->closedThrough, $this->closeOfDay, $this->history);
        $this->toast(__('Day :date closed.', ['date' => Carbon::parse($this->date)->format('d-m-Y')]));
    }

    public function confirmReopen(int $closeId): void
    {
        $this->authorize(Permission::DaysReopen->value);
        $this->resetErrorBag();
        $this->reopenId = $closeId;
        $this->reopenForm = ['reason' => ''];
        $this->showReopen = true;
    }

    public function reopen(ReopenDay $reopenDay): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $close = DayClose::query()->findOrFail($this->reopenId);
        $this->runAction(fn () => $reopenDay->handle($actor, $close, $this->reopenForm), 'reopenForm');

        $this->showReopen = false;
        unset($this->closedThrough, $this->closeOfDay, $this->history);
        $this->toast(__('Day :date reopened.', ['date' => $close->close_date->format('d-m-Y')]));
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();
        $day = $this->day();

        return view('livewire.reports.day-close-index', [
            'isClosed' => $day !== null && $this->closedThrough !== null && $day->lte($this->closedThrough),
            'canReopen' => $actor->can(Permission::DaysReopen->value),
        ])->title(__('Day close'));
    }
}
