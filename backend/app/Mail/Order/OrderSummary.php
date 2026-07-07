<?php

namespace HiEvents\Mail\Order;

use Barryvdh\DomPDF\Facade\Pdf;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\Email\DTO\RenderedEmailTemplateDTO;
use HiEvents\Services\Domain\Ticket\GenerateOrderTicketsPdfService;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @uses /backend/resources/views/emails/orders/summary.blade.php
 */
class OrderSummary extends BaseMail
{
    private readonly ?RenderedEmailTemplateDTO $renderedTemplate;

    public function __construct(
        private readonly OrderDomainObject        $order,
        private readonly EventDomainObject        $event,
        private readonly OrganizerDomainObject    $organizer,
        private readonly EventSettingDomainObject $eventSettings,
        private readonly ?InvoiceDomainObject     $invoice,
        private readonly GenerateOrderTicketsPdfService $generateOrderTicketsPdfService,
        private readonly LoggerInterface $logger,
        ?RenderedEmailTemplateDTO                 $renderedTemplate = null,
    )
    {
        $this->renderedTemplate = $renderedTemplate;

        parent::__construct();
    }

    public function envelope(): Envelope
    {
        $subject = $this->renderedTemplate?->subject ?? __('Your Order is Confirmed!') . '  🎉';

        return new Envelope(
            replyTo: $this->eventSettings->getSupportEmail(),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        if ($this->renderedTemplate) {
            return new Content(
                markdown: 'emails.custom-template',
                with: [
                    'renderedBody' => $this->renderedTemplate->body,
                    'renderedCta' => $this->renderedTemplate->cta,
                    'eventSettings' => $this->eventSettings,
                ]
            );
        }

        // Fallback to original template
        return new Content(
            markdown: 'emails.orders.summary',
            with: [
                'eventSettings' => $this->eventSettings,
                'event' => $this->event,
                'order' => $this->order,
                'organizer' => $this->organizer,
                'orderUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::ORDER_SUMMARY),
                    $this->event->getId(),
                    $this->order->getShortId(),
                )
            ]
        );
    }

    public function attachments(): array
    {
        $attachments = [];

        try {
            $ticketPdf = $this->generateOrderTicketsPdfService->generate(
                order: $this->order,
                event: $this->event,
                eventSettings: $this->eventSettings,
            );

            $attachments[] = Attachment::fromData(
                static fn() => $ticketPdf->content,
                $ticketPdf->filename,
            )->withMime('application/pdf');
        } catch (Throwable $exception) {
            $this->logger->error('Failed to generate ticket PDF attachment for order summary email', [
                'exception' => $exception,
                'order_id' => $this->order->getId(),
                'event_id' => $this->event->getId(),
            ]);
        }

        if ($this->invoice !== null) {
            $invoice = Pdf::loadView('invoice', [
                'order' => $this->order,
                'event' => $this->event,
                'organizer' => $this->organizer,
                'eventSettings' => $this->eventSettings,
                'invoice' => $this->invoice,
            ]);

            $attachments[] = Attachment::fromData(
                static fn() => $invoice->output(),
                'invoice.pdf',
            )->withMime('application/pdf');
        }

        return $attachments;
    }
}
