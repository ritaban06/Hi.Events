<?php
use Carbon\Carbon;
use HiEvents\Helper\Currency;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Support\Str;

/** @var \HiEvents\DomainObjects\EventDomainObject $event */
/** @var \HiEvents\DomainObjects\EventSettingDomainObject $eventSettings */
/** @var \Illuminate\Support\Collection<\HiEvents\DomainObjects\AttendeeDomainObject> $attendees */
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Tickets') }} - {{ $event->getTitle() }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #1a1a1a;
            background-color: #f3f4f6;
        }

        .page-break {
            page-break-after: always;
        }
        
        .ticket-page:last-child .page-break {
            page-break-after: auto;
        }

        .ticket-container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
            margin-top: 20px;
        }

        .header {
            padding: 24px;
            color: #ffffff;
        }

        .header-content {
            display: table;
            width: 100%;
        }

        .header-left {
            display: table-cell;
            vertical-align: middle;
        }

        .header-right {
            display: table-cell;
            text-align: right;
            vertical-align: middle;
            font-size: 18px;
            font-weight: bold;
        }

        .event-title {
            font-size: 24px;
            font-weight: bold;
            margin: 0;
        }

        .content {
            display: table;
            width: 100%;
            padding: 24px;
        }

        .content-left {
            display: table-cell;
            width: 60%;
            vertical-align: top;
            padding-right: 24px;
        }

        .content-right {
            display: table-cell;
            width: 40%;
            vertical-align: top;
            border-left: 1px dashed #e5e7eb;
            padding-left: 24px;
            text-align: center;
        }

        .detail-row {
            margin-bottom: 16px;
        }

        .detail-label {
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 4px;
            font-weight: bold;
        }

        .detail-value {
            font-size: 14px;
            color: #111827;
        }

        .attendee-section {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #f3f4f6;
        }

        .attendee-name {
            font-size: 18px;
            font-weight: bold;
            color: #111827;
        }

        .attendee-email {
            font-size: 14px;
            color: #6b7280;
            margin-top: 2px;
        }

        .qr-section {
            margin: 0 auto;
        }

        .qr-container {
            display: inline-block;
            padding: 12px;
            border: 2px solid;
            border-radius: 8px;
            background: #ffffff;
            margin-bottom: 16px;
        }

        .ticket-id {
            margin-top: 16px;
        }

        .ticket-id-value {
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 0.1em;
        }

        .footer {
            padding: 16px 24px;
            background-color: #f9fafb;
            border-top: 1px solid #e5e7eb;
            font-size: 12px;
            color: #6b7280;
            text-align: center;
        }

        .status-placeholder {
            display: inline-block;
            padding: 40px 20px;
            background: #f3f4f6;
            border-radius: 8px;
            margin-bottom: 16px;
            border: 2px dashed #d1d5db;
        }

        .status-text {
            font-weight: bold;
            font-size: 14px;
        }
        
        .status-cancelled {
            color: #dc2626;
        }
        
        .status-pending {
            color: #d97706;
        }

    </style>
</head>
<body>

@php
    $ticketDesignSettings = $eventSettings->getTicketDesignSettings();
    $accentColor = $ticketDesignSettings['accent_color'] ?? '#6B46C1';
    $footerText = $ticketDesignSettings['footer_text'] ?? null;
    $dateDisplayMode = $ticketDesignSettings['date_display_mode'] ?? 'START_DATE_TIME';
@endphp

@foreach($attendees as $attendee)
    @php
        $isCancelled = $attendee->getStatus() === 'CANCELLED';
        $isAwaitingPayment = $attendee->getStatus() === 'AWAITING_PAYMENT';
        
        // Ensure price formatting handles both null and 0 values
        $price = $attendee->getProduct() ? $attendee->getProduct()->getPrice() : 0;
        // Wait, if it's tiered, getPrice() might throw logic exception.
        // We should check product type or find the specific price by ID.
        if ($attendee->getProduct() && $attendee->getProduct()->isTieredType()) {
            $priceObj = $attendee->getProduct()->getPriceById($attendee->getProductPriceId());
            $price = $priceObj ? $priceObj->getPrice() : 0;
            $ticketTitle = $attendee->getProduct()->getTitle() . ' - ' . ($priceObj ? $priceObj->getName() : '');
        } else {
            $ticketTitle = $attendee->getProduct() ? $attendee->getProduct()->getTitle() : __('Ticket');
        }
    @endphp
    <div class="ticket-page">
        <div class="ticket-container">
            <div class="header" style="background-color: {{ $accentColor }};">
                <div class="header-content">
                    <div class="header-left">
                        <h1 class="event-title">{{ $event->getTitle() }}</h1>
                    </div>
                    <div class="header-right">
                        @if($price > 0)
                            {{ Currency::format($price, $event->getCurrency()) }}
                        @else
                            {{ __('Free') }}
                        @endif
                    </div>
                </div>
            </div>

            <div class="content">
                <div class="content-left">
                    <div class="detail-row">
                        <div class="detail-label">{{ __('Date & Time') }}</div>
                        <div class="detail-value">
                            @if($dateDisplayMode !== 'HIDDEN')
                                {{ Carbon::parse($event->getStartDate())->format('D, M j, Y g:i A') }}
                            @endif
                        </div>
                    </div>

                    @if($event->getOrganizer() && $event->getOrganizer()->getName())
                        <div class="detail-row">
                            <div class="detail-label">{{ __('Organizer') }}</div>
                            <div class="detail-value">
                                {{ $event->getOrganizer()->getName() }}
                            </div>
                        </div>
                    @endif

                    @if($eventSettings->getLocationDetails() && ($eventSettings->getLocationDetails()['venue_name'] ?? false || $eventSettings->getLocationDetails()['address_line_1'] ?? false))
                        <div class="detail-row">
                            <div class="detail-label">{{ __('Location') }}</div>
                            <div class="detail-value">
                                {{ $eventSettings->getLocationDetails()['venue_name'] ?? '' }}
                                @if(isset($eventSettings->getLocationDetails()['address_line_1']))
                                    <br>{{ $eventSettings->getLocationDetails()['address_line_1'] }}
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="detail-row">
                        <div class="detail-label">{{ __('Ticket Type') }}</div>
                        <div class="detail-value">
                            {{ $ticketTitle }}
                        </div>
                    </div>

                    <div class="attendee-section">
                        <div class="detail-label">{{ __('Attendee') }}</div>
                        <div class="attendee-name">
                            {{ $attendee->getFirstName() }} {{ $attendee->getLastName() }}
                        </div>
                        <div class="attendee-email">{{ $attendee->getEmail() }}</div>
                    </div>
                </div>

                <div class="content-right">
                    <div class="qr-section">
                        @if($isCancelled || $isAwaitingPayment)
                            <div class="status-placeholder">
                                <span class="status-text {{ $isCancelled ? 'status-cancelled' : 'status-pending' }}">
                                    {{ $isCancelled ? __('Cancelled') : __('Pay to unlock') }}
                                </span>
                            </div>
                        @else
                            <div class="qr-container" style="border-color: {{ $accentColor }};">
                                <img src="data:image/svg+xml;base64,{!! base64_encode(QrCode::format('svg')->size(180)->generate((string)$attendee->getPublicId())) !!}" />
                            </div>
                        @endif

                        <div class="ticket-id">
                            <div class="detail-label">{{ __('Ticket ID') }}</div>
                            <div class="ticket-id-value" style="color: {{ $accentColor }};">
                                {{ $attendee->getPublicId() }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @if($footerText)
                <div class="footer">
                    {{ $footerText }}
                </div>
            @endif
        </div>
        <div class="page-break"></div>
    </div>
@endforeach

</body>
</html>
