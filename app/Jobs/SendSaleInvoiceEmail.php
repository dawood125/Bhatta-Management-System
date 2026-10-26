<?php

namespace App\Jobs;

use App\Mail\SaleInvoiceMail;
use App\Models\EmailLog;
use App\Models\Kiln;
use App\Sales\InvoicePdf;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one logged invoice email with its PDF. Every attempt is counted on the log; a failure
 * (e.g. the mail server refusing) is written to the log and the queue tries again later, up to
 * EmailLog::MAX_ATTEMPTS times.
 */
class SendSaleInvoiceEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = EmailLog::MAX_ATTEMPTS;

    /** @var list<int> Seconds to wait before the second and third attempts. */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $emailLogId,
        public readonly int $kilnId,
    ) {}

    public function handle(CurrentKiln $currentKiln, InvoicePdf $invoicePdf): void
    {
        $kiln = Kiln::query()->find($this->kilnId);
        if ($kiln === null) {
            return;
        }

        // Queue workers have no logged-in kiln: work inside the email's own kiln.
        $currentKiln->run($kiln, function () use ($kiln, $invoicePdf): void {
            $log = EmailLog::query()->with('sale')->find($this->emailLogId);
            if ($log === null || $log->sale === null || $log->status === EmailLog::STATUS_SENT) {
                return;
            }

            $previousLocale = app()->getLocale();
            app()->setLocale($log->locale);

            try {
                $pdf = $invoicePdf->render($log->sale);
                Mail::to($log->recipientList())->send(new SaleInvoiceMail($log->sale, $kiln, $log->subject, $pdf));

                $log->forceFill(['status' => EmailLog::STATUS_SENT, 'attempts' => $log->attempts + 1, 'sent_at' => now(), 'last_error' => null])->save();
            } catch (Throwable $e) {
                $log->forceFill(['status' => EmailLog::STATUS_FAILED, 'attempts' => $log->attempts + 1, 'last_error' => Str::limit($e->getMessage(), 1000)])->save();

                throw $e;
            } finally {
                app()->setLocale($previousLocale);
            }
        });
    }
}
