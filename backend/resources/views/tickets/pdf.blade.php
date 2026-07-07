<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $event->getTitle() }} - {{ __('Tickets') }}</title>
    <style>
        @page {
            margin: 24px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2937;
            font-size: 12px;
            margin: 0;
        }

        .ticket {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
        }

        .ticket-header {
            background: {{ $ticketDesignSettings['accent_color'] ?? '#6B46C1' }};
            color: #ffffff;
            padding: 20px;
        }

        .event-title {
            margin: 0;
            font-size: 24px;
            line-height: 1.2;
        }

        .price {
            margin-top: 8px;
            font-size: 16px;
            font-weight: 600;
        }

        .ticket-body {
            padding: 20px;
        }

        .section-title {
            color: #6b7280;
            font-size: 11px;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .section-value {
            margin-bottom: 14px;
            font-size: 14px;
        }

        .attendee-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .qr-wrapper {
            text-align: center;
            margin-top: 16px;
            margin-bottom: 8px;
        }

        .ticket-id {
            text-align: center;
            font-weight: 700;
            color: {{ $ticketDesignSettings['accent_color'] ?? '#6B46C1' }};
            font-size: 16px;
        }

        .footer {
            margin-top: 16px;
            border-top: 1px solid #f3f4f6;
            padding-top: 12px;
            color: #6b7280;
            font-size: 11px;
            text-align: center;
        }

        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
@foreach($tickets as $index => $ticket)
    <div class="ticket">
        <div class="ticket-header">
            <h1 class="event-title">{{ $event->getTitle() }}</h1>
            <div class="price">{{ $ticket['ticket_price'] }}</div>
        </div>
        <div class="ticket-body">
            <div class="section-title">{{ __('Date & Time') }}</div>
            <div class="section-value">
                {{ \Carbon\Carbon::parse($event->getStartDate(), $event->getTimezone())->format('M j, Y g:i A') }}
                @if($event->getTimezone())
                    ({{ $event->getTimezone() }})
                @endif
            </div>

            @if($eventSettings->getLocationDetails())
                <div class="section-title">{{ __('Location') }}</div>
                <div class="section-value">{{ $eventSettings->getAddressString() }}</div>
            @endif

            <div class="section-title">{{ __('Ticket Type') }}</div>
            <div class="section-value">{{ $ticket['ticket_type'] }}</div>

            <div class="section-title">{{ __('Attendee') }}</div>
            <div class="attendee-name">{{ $ticket['attendee']->getFirstName() }} {{ $ticket['attendee']->getLastName() }}</div>
            <div class="section-value">{{ $ticket['attendee']->getEmail() }}</div>

            <div class="qr-wrapper">
                <img src="{{ $ticket['qr_code_url'] }}" alt="{{ __('Ticket QR Code') }}" width="180" height="180">
            </div>
            <div class="ticket-id">{{ $ticket['attendee']->getPublicId() }}</div>

            @if(!empty($ticketDesignSettings['footer_text']))
                <div class="footer">{{ $ticketDesignSettings['footer_text'] }}</div>
            @endif
        </div>
    </div>

    @if($index !== $tickets->count() - 1)
        <div class="page-break"></div>
    @endif
@endforeach
</body>
</html>
