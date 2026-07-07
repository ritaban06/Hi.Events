<?php

namespace Tests\Unit\Services\Domain\Ticket;

use Barryvdh\DomPDF\Facade\Pdf;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Domain\Ticket\GenerateOrderTicketsPdfService;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GenerateOrderTicketsPdfServiceTest extends TestCase
{
    private MockInterface|AttendeeRepositoryInterface $attendeeRepository;
    private GenerateOrderTicketsPdfService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->service = new GenerateOrderTicketsPdfService($this->attendeeRepository);
    }

    public function testGeneratesSingleTicketPdfWithSingleFilename(): void
    {
        $attendee = $this->mockAttendee(id: 10, productId: 111, publicId: 'ATT-1');
        $order = $this->mockOrderWithAttendees(collect([$attendee]));
        $event = $this->mockEvent();
        $eventSettings = $this->mockEventSettings();
        $pdf = Mockery::mock();

        $this->attendeeRepository->shouldReceive('findWhere')->never();

        Pdf::shouldReceive('loadView')
            ->once()
            ->with('tickets.pdf', Mockery::on(function (array $payload) {
                return $payload['tickets']->count() === 1
                    && str_contains($payload['tickets']->first()['qr_code_url'], 'ATT-1');
            }))
            ->andReturn($pdf);

        $pdf->shouldReceive('setOption')->once()->with(['isRemoteEnabled' => true])->andReturnSelf();
        $pdf->shouldReceive('output')->once()->andReturn('pdf-single');

        $result = $this->service->generate($order, $event, $eventSettings);

        $this->assertSame('Ticket.pdf', $result->filename);
        $this->assertSame('pdf-single', $result->content);
    }

    public function testGeneratesMultiTicketPdfWithPluralFilename(): void
    {
        $attendeeA = $this->mockAttendee(id: 10, productId: 111, publicId: 'ATT-1');
        $attendeeB = $this->mockAttendee(id: 11, productId: 111, publicId: 'ATT-2');
        $order = $this->mockOrderWithAttendees(collect([$attendeeA, $attendeeB]));
        $event = $this->mockEvent();
        $eventSettings = $this->mockEventSettings();
        $pdf = Mockery::mock();

        $this->attendeeRepository->shouldReceive('findWhere')->never();

        Pdf::shouldReceive('loadView')
            ->once()
            ->with('tickets.pdf', Mockery::on(
                static fn(array $payload) => $payload['tickets']->count() === 2
            ))
            ->andReturn($pdf);

        $pdf->shouldReceive('setOption')->once()->with(['isRemoteEnabled' => true])->andReturnSelf();
        $pdf->shouldReceive('output')->once()->andReturn('pdf-multi');

        $result = $this->service->generate($order, $event, $eventSettings);

        $this->assertSame('Tickets.pdf', $result->filename);
        $this->assertSame('pdf-multi', $result->content);
    }

    public function testUsesFallbackAttendeeWhenOrderHasNoAttendeeCollection(): void
    {
        $fallbackAttendee = $this->mockAttendee(id: 12, productId: 111, publicId: 'ATT-FALLBACK');
        $order = $this->mockOrderWithAttendees(null);
        $event = $this->mockEvent();
        $eventSettings = $this->mockEventSettings();
        $pdf = Mockery::mock();

        $this->attendeeRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with(['order_id' => 123])
            ->andReturn(collect());

        Pdf::shouldReceive('loadView')
            ->once()
            ->with('tickets.pdf', Mockery::on(function (array $payload) {
                return $payload['tickets']->count() === 1
                    && str_contains($payload['tickets']->first()['qr_code_url'], 'ATT-FALLBACK');
            }))
            ->andReturn($pdf);

        $pdf->shouldReceive('setOption')->once()->with(['isRemoteEnabled' => true])->andReturnSelf();
        $pdf->shouldReceive('output')->once()->andReturn('pdf-fallback');

        $result = $this->service->generate($order, $event, $eventSettings, $fallbackAttendee);

        $this->assertSame('Ticket.pdf', $result->filename);
        $this->assertSame('pdf-fallback', $result->content);
    }

    private function mockOrderWithAttendees(?Collection $attendees): MockInterface|OrderDomainObject
    {
        $orderItem = Mockery::mock(OrderItemDomainObject::class);
        $orderItem->shouldReceive('getProductId')->andReturn(111);
        $orderItem->shouldReceive('getItemName')->andReturn('VIP');
        $orderItem->shouldReceive('getPrice')->andReturn(99.99);

        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getAttendees')->andReturn($attendees);
        $order->shouldReceive('getOrderItems')->andReturn(collect([$orderItem]));
        $order->shouldReceive('getId')->andReturn(123);

        return $order;
    }

    private function mockAttendee(int $id, int $productId, string $publicId): MockInterface|AttendeeDomainObject
    {
        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getId')->andReturn($id);
        $attendee->shouldReceive('getProductId')->andReturn($productId);
        $attendee->shouldReceive('getPublicId')->andReturn($publicId);
        $attendee->shouldReceive('getFirstName')->andReturn('Jane');
        $attendee->shouldReceive('getLastName')->andReturn('Doe');
        $attendee->shouldReceive('getEmail')->andReturn('jane@example.com');

        return $attendee;
    }

    private function mockEvent(): MockInterface|EventDomainObject
    {
        $event = Mockery::mock(EventDomainObject::class);
        $event->shouldReceive('getCurrency')->andReturn('USD');
        $event->shouldReceive('getTitle')->andReturn('Demo Event');
        $event->shouldReceive('getStartDate')->andReturn('2026-07-07 12:00:00');
        $event->shouldReceive('getTimezone')->andReturn('UTC');

        return $event;
    }

    private function mockEventSettings(): MockInterface|EventSettingDomainObject
    {
        $eventSettings = Mockery::mock(EventSettingDomainObject::class);
        $eventSettings->shouldReceive('getTicketDesignSettings')->andReturn([]);
        $eventSettings->shouldReceive('getLocationDetails')->andReturn([]);
        $eventSettings->shouldReceive('getAddressString')->andReturn('');

        return $eventSettings;
    }
}
