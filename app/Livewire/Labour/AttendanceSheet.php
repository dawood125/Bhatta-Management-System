<?php

namespace App\Livewire\Labour;

use App\Actions\Labour\PostSalaries;
use App\Actions\Labour\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\PayBasis;
use App\Enums\Permission;
use App\Labour\WorkerLedger;
use App\Livewire\Concerns\RunsActions;
use App\Models\Attendance;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\WorkEntry;
use App\Models\Worker;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Salaried workers: the attendance sheet of a day (only absences and half days need marking)
 * and the month's salaries, worked out from attendance and posted once the month is done.
 *
 * @property-read Collection<int, Worker> $workers Livewire computed property
 * @property-read list<array{worker: Worker, rate: Money|null, absent: int, half: int, salary: Money|null, posted: WorkEntry|null}> $salaries Livewire computed property
 */
class AttendanceSheet extends Component
{
    use RunsActions;

    #[Url]
    public string $date = '';

    #[Url]
    public string $month = '';

    /** @var array<int|string, string> worker id => status */
    public array $marks = [];

    public function mount(): void
    {
        $this->authorize(Permission::LabourView->value);
        $this->date = $this->validDate($this->date) ?? now()->format('Y-m-d');
        $this->month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) ? $this->month : now()->format('Y-m');
        $this->loadMarks();
    }

    public function updatedDate(): void
    {
        $this->resetErrorBag();
        if ($this->validDate($this->date) !== null) {
            $this->month = substr($this->date, 0, 7);
        }
        $this->loadMarks();
    }

    public function updatedMonth(): void
    {
        $this->resetErrorBag();
        unset($this->salaries);
    }

    /**
     * Active salaried workers.
     *
     * @return Collection<int, Worker>
     */
    #[Computed]
    public function workers(): Collection
    {
        return Worker::query()
            ->with('category')
            ->where('is_active', true)
            ->whereHas('category', fn (Builder $q) => $q->where('basis', PayBasis::PerMonth->value))
            ->orderBy('name')
            ->get();
    }

    /**
     * The month's salary for each salaried worker: rate, days off, the salary, and whether it is posted.
     *
     * @return list<array{worker: Worker, rate: Money|null, absent: int, half: int, salary: Money|null, posted: WorkEntry|null}>
     */
    #[Computed]
    public function salaries(): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            return [];
        }

        $start = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $ids = $this->workers->pluck('id');

        $counts = Attendance::query()
            ->whereIn('worker_id', $ids)
            ->whereBetween('attendance_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->toBase()
            ->groupBy('worker_id', 'status')
            ->selectRaw('worker_id, status, COUNT(*) AS n')
            ->get();

        $posted = WorkEntry::query()
            ->with('entry')
            ->whereIn('worker_id', $ids)
            ->where('salary_month', $this->month)
            ->whereHas('entry', fn (Builder $q) => $q->where('status', JournalEntry::STATUS_POSTED))
            ->get()
            ->keyBy('worker_id');

        $ledger = app(WorkerLedger::class);
        $postSalaries = app(PostSalaries::class);
        $rows = [];
        foreach ($this->workers as $worker) {
            $mine = $counts->where('worker_id', $worker->id);
            $rate = $ledger->rateFor($worker, $end->isAfter(now()) ? now() : $end);
            $rows[] = [
                'worker' => $worker,
                'rate' => $rate?->rate,
                'absent' => (int) $mine->firstWhere('status', AttendanceStatus::Absent->value)?->n,
                'half' => (int) $mine->firstWhere('status', AttendanceStatus::Half->value)?->n,
                'salary' => $posted->get($worker->id)->amount ?? $postSalaries->preview($worker, $this->month),
                'posted' => $posted->get($worker->id),
            ];
        }

        return $rows;
    }

    public function markAll(string $status): void
    {
        if (AttendanceStatus::tryFrom($status) === null) {
            return;
        }

        foreach ($this->workers as $worker) {
            $this->marks[$worker->id] = $status;
        }
    }

    public function saveAttendance(RecordAttendance $recordAttendance): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        $marks = [];
        foreach ($this->workers as $worker) {
            $marks[$worker->id] = $this->marks[$worker->id] ?? AttendanceStatus::Present->value;
        }

        $this->runAction(fn () => $recordAttendance->handle($actor, ['attendance_date' => $this->date, 'marks' => $marks]));

        $this->toast(__('Attendance saved for :date.', ['date' => Carbon::parse($this->date)->format('d-m-Y')]));
        unset($this->salaries);
    }

    public function postSalaries(PostSalaries $postSalaries): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        $posted = $this->runAction(fn () => $postSalaries->handle($actor, ['month' => $this->month]));

        $this->toast($posted === []
            ? __('Nothing to post: every salary of this month is already posted.')
            : trans_choice('{1} One salary posted.|[2,*] :count salaries posted.', count($posted), ['count' => count($posted)]));
        unset($this->salaries);
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        $monthStart = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) ? Carbon::createFromFormat('Y-m-d', $this->month.'-01') : null;

        return view('livewire.labour.attendance-sheet', [
            'canRecord' => $actor->can(Permission::LabourRecord->value),
            'monthLabel' => $monthStart?->translatedFormat('F Y'),
            'monthOpen' => $monthStart !== null && $monthStart->copy()->endOfMonth()->isFuture(),
            'dateValid' => $this->validDate($this->date) !== null,
        ])->title(__('Attendance and salaries'));
    }

    private function loadMarks(): void
    {
        $this->marks = [];
        if ($this->validDate($this->date) === null) {
            return;
        }

        $saved = Attendance::query()->where('attendance_date', $this->date)->whereIn('worker_id', $this->workers->pluck('id'))->get()->keyBy('worker_id');
        foreach ($this->workers as $worker) {
            $this->marks[$worker->id] = $saved->get($worker->id)?->status->value ?? AttendanceStatus::Present->value;
        }
    }

    private function validDate(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = Carbon::createFromFormat('Y-m-d', $value);

        return $date !== null && $date->format('Y-m-d') === $value ? $value : null;
    }
}
