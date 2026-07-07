<?php

namespace HiEvents\Services\Domain\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Services\Domain\Email\MailBuilderService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Collection;

class SendAttendeeTicketService
{
    public function __construct(
        private readonly Mailer             $mailer,
        private readonly MailBuilderService $mailBuilderService,
    )
    {
    }

    public function send(
        OrderDomainObject        $order,
        Collection               $attendees,
        EventDomainObject        $event,
        EventSettingDomainObject $eventSettings,
        OrganizerDomainObject    $organizer,
    ): void
    {
        $mail = $this->mailBuilderService->buildAttendeeTicketMail(
            $attendees,
            $order,
            $event,
            $eventSettings,
            $organizer
        );

        $firstAttendee = $attendees->first();

        $this->mailer
            ->to($firstAttendee->getEmail())
            ->locale($firstAttendee->getLocale())
            ->send($mail);
    }
}
