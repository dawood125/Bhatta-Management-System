<?php

namespace App\Actions\Sales;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Jobs\SendSaleInvoiceEmail;
use App\Models\EmailLog;
use App\Models\Sale;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Validation\ValidationException;

/**
 * Emails a sale's invoice (PDF attached) to the kiln's sales email addresses, through the queue:
 * the email is logged first, and the log shows when it was sent, or the error and the retries.
 * New sales are emailed automatically when sales emails are switched on (proposed default for
 * Q-34); "Email invoice" on the sale page sends it again on request.
 */
class EmailSaleInvoice
{
    use ValidatesSettings;

    public function __construct(private readonly CurrentKiln $currentKiln) {}

    /** "Email invoice" on the sale page: a new email (and log entry) every time. */
    public function handle(User $actor, Sale $sale): EmailLog
    {
        $this->authorizeSettings($actor, Permission::SalesRecord);

        if ($sale->isCancelled()) {
            throw ValidationException::withMessages(['email' => __('This sale was cancelled.')]);
        }

        $recipients = $this->currentKiln->get()->salesEmailAddresses();
        if ($recipients === []) {
            throw ValidationException::withMessages(['email' => __('Add the email addresses for sales emails in the kiln profile first.')]);
        }

        return $this->queue($sale, $recipients, $actor);
    }

    /** A new sale's email, when the kiln has sales emails switched on. Runs inside the sale's transaction. */
    public function queueIfEnabled(Sale $sale, User $actor): ?EmailLog
    {
        $recipients = $this->currentKiln->get()->salesEmailRecipients();

        return $recipients === [] ? null : $this->queue($sale, $recipients, $actor);
    }

    /**
     * @param  list<string>  $recipients
     */
    private function queue(Sale $sale, array $recipients, User $actor): EmailLog
    {
        // The email (and its PDF) is in the language of the person who recorded or sent it.
        $locale = in_array($actor->locale, ['en', 'ur'], true) ? $actor->locale : app()->getLocale();
        $kiln = $this->currentKiln->get();

        $log = EmailLog::query()->create([
            'sale_id' => $sale->id,
            'kind' => EmailLog::KIND_SALE_INVOICE,
            'recipients' => implode(', ', $recipients),
            'subject' => __('Invoice :number from :kiln', ['number' => $sale->number, 'kiln' => $locale === 'ur' && filled($kiln->name_ur) ? $kiln->name_ur : $kiln->name], $locale),
            'locale' => $locale,
            'status' => EmailLog::STATUS_QUEUED,
            'attempts' => 0,
            'created_by' => $actor->id,
        ]);

        // Only once the sale is really saved: a sale that rolls back sends nothing.
        SendSaleInvoiceEmail::dispatch($log->id, $log->kiln_id)->afterCommit();

        return $log;
    }
}
