<?php

namespace App\Livewire\Sales;

use App\Actions\Sales\EmailSaleInvoice;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\EmailLog;
use App\Models\Sale;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The invoice emails: when each was sent, to whom, how many attempts it took, and the error of a
 * failed one, with "Send again".
 *
 * @property-read LengthAwarePaginator<int, EmailLog> $emails Livewire computed property
 */
class EmailLogIndex extends Component
{
    use RunsActions, WithPagination;

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize(Permission::SalesView->value);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, EmailLog>
     */
    #[Computed]
    public function emails(): LengthAwarePaginator
    {
        return EmailLog::query()
            ->with(['sale.customer', 'creator'])
            ->when(in_array($this->status, [EmailLog::STATUS_QUEUED, EmailLog::STATUS_SENT, EmailLog::STATUS_FAILED], true), fn ($q) => $q->where('status', $this->status))
            ->latest('id')
            ->paginate(25);
    }

    public function resend(int $saleId, EmailSaleInvoice $emailInvoice): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $sale = Sale::query()->findOrFail($saleId);
        $log = $this->runAction(fn () => $emailInvoice->handle($actor, $sale));

        unset($this->emails);
        $this->toast(__('The invoice will be emailed to :to.', ['to' => $log->recipients]));
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();
        $kiln = app(CurrentKiln::class)->get();

        return view('livewire.sales.email-log-index', [
            'canSend' => $actor->can(Permission::SalesRecord->value),
            'canSettings' => $actor->can(Permission::KilnProfileManage->value),
            'kiln' => $kiln,
        ])->title(__('Invoice emails'));
    }
}
