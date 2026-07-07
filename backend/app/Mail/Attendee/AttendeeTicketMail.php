<?php

namespace HiEvents\Mail\Attendee;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\StringHelper;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\Email\DTO\RenderedEmailTemplateDTO;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;
use Illuminate\Support\Facades\Log;

use HiEvents\Services\Domain\Attendee\GenerateTicketPdfService;
use Illuminate\Support\Collection;

/**
 * @uses /backend/resources/views/emails/orders/attendee-ticket.blade.php
 */
class AttendeeTicketMail extends BaseMail
{
    private readonly ?RenderedEmailTemplateDTO $renderedTemplate;
    private readonly AttendeeDomainObject $firstAttendee;

    /**
     * @param Collection<AttendeeDomainObject> $attendees
     */
    public function __construct(
        private readonly OrderDomainObject        $order,
        private readonly Collection               $attendees,
        private readonly EventDomainObject        $event,
        private readonly EventSettingDomainObject $eventSettings,
        private readonly OrganizerDomainObject    $organizer,
        ?RenderedEmailTemplateDTO                 $renderedTemplate = null,
    )
    {
        parent::__construct();
        $this->renderedTemplate = $renderedTemplate;
        $this->firstAttendee = $this->attendees->first();
    }

    public function envelope(): Envelope
    {
        $subject = $this->renderedTemplate?->subject ?? __('🎟️ Your Ticket for :event', [
            'event' => Str::limit($this->event->getTitle(), 50)
        ]);

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

        // If no template is provided, use the default blade template
        return new Content(
            markdown: 'emails.orders.attendee-ticket',
            with: [
                'event' => $this->event,
                'attendee' => $this->firstAttendee,
                'eventSettings' => $this->eventSettings,
                'organizer' => $this->organizer,
                'order' => $this->order,
                'ticketUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET),
                    $this->event->getId(),
                    $this->firstAttendee->getShortId(),
                )
            ]
        );
    }

    public function attachments(): array
    {
        $startDateTime = Carbon::parse($this->event->getStartDate(), $this->event->getTimezone());
        $endDateTime = $this->event->getEndDate() ? Carbon::parse($this->event->getEndDate(), $this->event->getTimezone()) : null;

        $event = Event::create()
            ->name($this->event->getTitle())
            ->uniqueIdentifier('event-' . $this->firstAttendee->getId())
            ->startsAt($startDateTime)
            ->url($this->event->getEventUrl())
            ->organizer($this->organizer->getEmail(), $this->organizer->getName());

        if ($this->event->getDescription()) {
            $event->description(StringHelper::previewFromHtml($this->event->getDescription()));
        }

        if ($this->eventSettings->getLocationDetails()) {
            $event->address($this->eventSettings->getAddressString());
        }

        if ($endDateTime) {
            $event->endsAt($endDateTime);
        }

        $calendar = Calendar::create()
            ->event($event)
            ->get();

        $attachments = [
            Attachment::fromData(static fn() => $calendar, 'event.ics')
                ->withMime('text/calendar')
        ];

        try {
            $pdfService = app(GenerateTicketPdfService::class);
            $pdf = $pdfService->generate($this->attendees, $this->event);
            
            $filename = $this->attendees->count() > 1 ? 'tickets.pdf' : 'ticket.pdf';
            $pdfOutput = $pdf->output();
            
            $attachments[] = Attachment::fromData(static fn() => $pdfOutput, $filename)
                ->withMime('application/pdf');
        } catch (\Throwable $e) {
            Log::error('Failed to generate ticket PDF for email', [
                'order_id' => $this->order->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $attachments;
    }
}
