<?php

use App\Actions\Sales\CancelSale;
use App\Actions\Sales\EmailSaleInvoice;
use App\Actions\Settings\SaveSalesSettings;
use App\Enums\Role;
use App\Jobs\SendSaleInvoiceEmail;
use App\Models\EmailLog;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    $this->kiln = makeSeededKiln(['name' => 'Demo Kiln']);
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    $this->customer = makeParty($this->owner, 'Demo Customer');
    makeSaleableStock($this->owner, ['awal' => '8000']);
});

/** Switch sales emails on or off for the current kiln (through the real settings action). */
function salesEmails(bool $enabled, string $to = 'owner@example.com, Partner@Example.com'): void
{
    app(SaveSalesSettings::class)->handle(auth()->user(), app(CurrentKiln::class)->get(), [
        'sales_email_enabled' => $enabled, 'sales_email_to' => $to, 'invoice_note' => '',
    ]);
}

/** @return list<Email> */
function sentEmails(): array
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()
        ->map(fn (SentMessage $message) => $message->getOriginalMessage())
        ->values()
        ->all();
}

/** A mail server that refuses every message. */
function useFailingMailServer(): void
{
    Mail::extend('failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection refused by the mail server');
        }

        public function __toString(): string
        {
            return 'failing://';
        }
    });
    config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
    app('mail.manager')->forgetMailers();
}

describe('agreed acceptance example', function () {
    it('emails each new sale to the owner with the invoice PDF attached, and logs it as sent', function () {
        salesEmails(true);
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);

        $log = EmailLog::query()->sole();
        expect($log->status)->toBe(EmailLog::STATUS_SENT)
            ->and($log->attempts)->toBe(1)
            ->and($log->sent_at)->not->toBeNull()
            ->and($log->recipients)->toBe('owner@example.com, partner@example.com')
            ->and($log->subject)->toBe("Invoice {$sale->number} from Demo Kiln")
            ->and($log->sale_id)->toBe($sale->id);

        $emails = sentEmails();
        expect($emails)->toHaveCount(1);

        $email = $emails[0];
        $attachments = $email->getAttachments();
        expect(array_map(fn ($address) => $address->getAddress(), $email->getTo()))->toBe(['owner@example.com', 'partner@example.com'])
            ->and($email->getSubject())->toBe("Invoice {$sale->number} from Demo Kiln")
            ->and($email->getHtmlBody())->toContain('Demo Customer')->toContain('Rs 75,000.00')
            ->and($attachments)->toHaveCount(1)
            ->and($attachments[0]->getFilename())->toBe("{$sale->number}.pdf")
            ->and($attachments[0]->getContentType())->toBe('application/pdf')
            ->and(str_starts_with($attachments[0]->getBody(), '%PDF'))->toBeTrue();
    });

    it('logs a mail server failure, and the next attempt sends it', function () {
        salesEmails(true);
        Queue::fake();
        recordSale($this->owner, $this->customer, ['awal' => ['5000', '15000']]);

        Queue::assertPushed(SendSaleInvoiceEmail::class, 1);
        $log = EmailLog::query()->sole();
        expect($log->status)->toBe(EmailLog::STATUS_QUEUED)
            ->and($log->statusLabel())->toBe('Waiting to send');

        $job = new SendSaleInvoiceEmail($log->id, $log->kiln_id);
        expect($job->tries)->toBe(EmailLog::MAX_ATTEMPTS);

        useFailingMailServer();
        expect(fn () => app()->call([$job, 'handle']))->toThrow(TransportException::class);

        $log->refresh();
        expect($log->status)->toBe(EmailLog::STATUS_FAILED)
            ->and($log->attempts)->toBe(1)
            ->and($log->last_error)->toBe('Connection refused by the mail server')
            ->and($log->willRetry())->toBeTrue()
            ->and($log->statusLabel())->toBe('Failed, will retry')
            // The job ran in the email's own kiln and gave the test's kiln back.
            ->and(app(CurrentKiln::class)->id())->toBe($this->kiln->id);

        config(['mail.default' => 'array']);
        app('mail.manager')->forgetMailers();
        app()->call([$job, 'handle']);

        $log->refresh();
        expect($log->status)->toBe(EmailLog::STATUS_SENT)
            ->and($log->attempts)->toBe(2)
            ->and($log->last_error)->toBeNull()
            ->and(sentEmails())->toHaveCount(1);
    });
});

describe('sending', function () {
    it('sends nothing when sales emails are off, and asks for addresses before "Email invoice"', function () {
        salesEmails(false, '');
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['1000', '15000']]);
        expect(EmailLog::query()->count())->toBe(0);

        expect(validationErrors(fn () => app(EmailSaleInvoice::class)->handle($this->owner, $sale))['email'])
            ->toBe('Add the email addresses for sales emails in the kiln profile first.');

        // Addresses saved but automatic emails off: sending on request still works.
        salesEmails(false, 'owner@example.com');
        app(EmailSaleInvoice::class)->handle($this->owner, $sale);
        expect(EmailLog::query()->sole()->status)->toBe(EmailLog::STATUS_SENT);
    });

    it('emails a sale again on request as a new entry, but not a cancelled one, and only for those who record sales', function () {
        salesEmails(true);
        $sale = recordSale($this->owner, $this->customer, ['awal' => ['1000', '15000']]);
        app(EmailSaleInvoice::class)->handle($this->owner, $sale);

        expect(EmailLog::query()->where('status', EmailLog::STATUS_SENT)->count())->toBe(2)
            ->and(fn () => app(EmailSaleInvoice::class)->handle(makeUser($this->kiln, Role::Viewer), $sale))->toThrow(AuthorizationException::class);

        app(CancelSale::class)->handle($this->owner, $sale, ['reason' => 'Entered twice']);
        expect(validationErrors(fn () => app(EmailSaleInvoice::class)->handle($this->owner, $sale->fresh()))['email'])->toBe('This sale was cancelled.');
    });

    it('writes the email in the language of the person who recorded the sale', function () {
        salesEmails(true);
        $this->owner->forceFill(['locale' => 'ur'])->save();
        recordSale($this->owner, $this->customer, ['awal' => ['1000', '15000']]);

        expect(EmailLog::query()->sole()->locale)->toBe('ur')
            ->and(sentEmails()[0]->getHtmlBody())->toContain('dir="rtl"');
    });
});

describe('settings', function () {
    it('lets only the owner change sales emails, and checks the addresses', function () {
        $kiln = app(CurrentKiln::class)->get();
        $settings = fn (array $input) => app(SaveSalesSettings::class)->handle($this->owner, $kiln, $input + ['sales_email_enabled' => true, 'invoice_note' => '']);

        expect(fn () => app(SaveSalesSettings::class)->handle(makeUser($this->kiln, Role::Manager), $kiln, ['sales_email_enabled' => false]))->toThrow(AuthorizationException::class)
            ->and(validationErrors(fn () => $settings(['sales_email_to' => 'owner@example.com, not-an-email']))['sales_email_to'])->toBe('"not-an-email" is not a valid email address.')
            ->and(validationErrors(fn () => $settings(['sales_email_to' => '']))['sales_email_to'])->toBe('Enter at least one address to email new sales to.')
            ->and(validationErrors(fn () => $settings(['sales_email_to' => 'a@x.pk,b@x.pk,c@x.pk,d@x.pk,e@x.pk,f@x.pk']))['sales_email_to'])->toBe('At most 5 addresses.');

        $settings(['sales_email_to' => ' Owner@Example.com , owner@example.com, son@example.com ', 'invoice_note' => 'Bank: Demo Bank 0001']);
        expect($kiln->fresh()->sales_email_to)->toBe('owner@example.com, son@example.com')
            ->and($kiln->fresh()->invoice_note)->toBe('Bank: Demo Bank 0001');
    });
});
