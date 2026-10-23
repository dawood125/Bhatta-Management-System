<?php

namespace App\Mail;

use App\Models\Kiln;
use App\Models\Sale;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A sale's invoice for the owner: a short summary in the email, the invoice as a PDF attachment.
 * Sent by SendSaleInvoiceEmail (already on the queue), so the mailable itself is not queued.
 */
class SaleInvoiceMail extends Mailable
{
    public function __construct(
        public readonly Sale $sale,
        public readonly Kiln $kiln,
        private readonly string $subjectLine,
        private readonly string $pdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->kiln->displayName()),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.sale-invoice');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->sale->number.'.pdf')->withMime('application/pdf')];
    }
}
