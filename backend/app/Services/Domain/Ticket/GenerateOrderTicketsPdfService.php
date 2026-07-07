<?php

namespace HiEvents\Services\Domain\Ticket;

use Barryvdh\DomPDF\Facade\Pdf;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Helper\Currency;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Domain\Ticket\DTO\TicketPdfResponseDTO;
use Illuminate\Support\Collection;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GenerateOrderTicketsPdfService
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
    )
    {
    }

    public function generate(
        OrderDomainObject $order,
        EventDomainObject $event,
        EventSettingDomainObject $eventSettings,
        ?AttendeeDomainObject $fallbackAttendee = null,
    ): TicketPdfResponseDTO
    {
        $attendees = $this->resolveAttendees($order, $fallbackAttendee);

        if ($attendees->isEmpty()) {
            throw new ResourceNotFoundException(__('No attendees found for order'));
        }

        $orderItems = collect($order->getOrderItems() ?? [])
            ->keyBy(static fn(OrderItemDomainObject $item) => $item->getProductId());

        $ticketDesignSettings = $eventSettings->getTicketDesignSettings();

        if (!is_array($ticketDesignSettings)) {
            $ticketDesignSettings = [];
        }

        $tickets = $attendees->map(function (AttendeeDomainObject $attendee) use ($orderItems, $event) {
            $orderItem = $orderItems->get($attendee->getProductId());

            return [
                'attendee' => $attendee,
                'ticket_type' => $orderItem?->getItemName() ?? __('General Admission'),
                'ticket_price' => Currency::format($orderItem?->getPrice() ?? 0, $event->getCurrency()),
                'qr_code_url' => $this->buildQrCodeUrl($attendee),
            ];
        })->values();

        $pdf = Pdf::loadView('tickets.pdf', [
            'event' => $event,
            'eventSettings' => $eventSettings,
            'tickets' => $tickets,
            'ticketDesignSettings' => $ticketDesignSettings,
        ]);
        $pdf->setOption(['isRemoteEnabled' => true]);

        return new TicketPdfResponseDTO(
            content: $pdf->output(),
            filename: $tickets->count() > 1 ? 'Tickets.pdf' : 'Ticket.pdf',
        );
    }

    private function resolveAttendees(
        OrderDomainObject $order,
        ?AttendeeDomainObject $fallbackAttendee = null,
    ): Collection
    {
        $orderAttendees = $order->getAttendees();

        if ($orderAttendees && $orderAttendees->isNotEmpty()) {
            return $orderAttendees;
        }

        $attendees = $this->attendeeRepository->findWhere([
            'order_id' => $order->getId(),
        ]);

        if ($attendees->isNotEmpty()) {
            return $attendees;
        }

        if ($fallbackAttendee) {
            return collect([$fallbackAttendee]);
        }

        return collect();
    }

    private function buildQrCodeUrl(AttendeeDomainObject $attendee): string
    {
        return sprintf(
            'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=%s',
            urlencode((string)$attendee->getPublicId()),
        );
    }
}
