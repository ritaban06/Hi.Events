<?php

namespace HiEvents\Jobs\Integrations;

use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ClearValuesRequest;
use Google_Service_Sheets_ValueRange;
use HiEvents\Models\Attendee;
use HiEvents\Models\AttendeeCheckIn;
use HiEvents\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncGoogleSheetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(private readonly int $eventId)
    {
    }

    public function handle(): void
    {
        $spreadsheetId = config('services.google_sheets.spreadsheet_id');
        $credentialsPath = config('services.google_sheets.credentials_path');

        if (!$spreadsheetId || !$credentialsPath) {
            Log::error('Google Sheets Sync: Missing configuration.');
            return;
        }

        try {
            $client = new Google_Client();
            $client->setApplicationName('Hi.Events Google Sheets Sync');
            $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
            
            // Allow JSON string directly from env, or a path
            if (is_string($credentialsPath) && str_starts_with(trim($credentialsPath), '{')) {
                $client->setAuthConfig(json_decode($credentialsPath, true));
            } else {
                $client->setAuthConfig($credentialsPath);
            }

            $client->setAccessType('offline');

            $service = new Google_Service_Sheets($client);

            $this->syncOrders($service, $spreadsheetId, $this->eventId);
            $this->syncAttendees($service, $spreadsheetId, $this->eventId);
            $this->syncCheckIns($service, $spreadsheetId, $this->eventId);

        } catch (\Throwable $e) {
            Log::error('Google Sheets Sync Failed: ' . $e->getMessage(), ['exception' => $e]);
            throw $e;
        }
    }

    private function syncOrders(Google_Service_Sheets $service, string $spreadsheetId, int $eventId): void
    {
        // For large datasets, it's better to chunk, but we'll use get() for simplicity unless it fails
        $orders = Order::with('order_items.product')->where('event_id', $eventId)->get();
        $data = [
            ['ID', 'Short ID', 'Event ID', 'First Name', 'Last Name', 'Email', 'Products', 'Total Gross', 'Currency', 'Status', 'Payment Status', 'Created At']
        ];

        foreach ($orders as $order) {
            $data[] = [
                $order->id ?? '',
                $order->short_id ?? '',
                $order->event_id ?? '',
                $order->first_name ?? '',
                $order->last_name ?? '',
                $order->email ?? '',
                $order->order_items->map(fn($item) => $item->product ? $item->product->title : '')->filter()->unique()->implode(', '),
                $order->total_gross ?? '',
                $order->currency ?? '',
                $order->status ?? '',
                $order->payment_status ?? '',
                $order->created_at ? (is_string($order->created_at) ? $order->created_at : $order->created_at->toDateTimeString()) : '',
            ];
        }

        $this->updateSheet($service, $spreadsheetId, 'Orders', $data);
    }

    private function syncAttendees(Google_Service_Sheets $service, string $spreadsheetId, int $eventId): void
    {
        $attendees = Attendee::with('product')->where('event_id', $eventId)->get();
        $data = [
            ['ID', 'Short ID', 'Order ID', 'Event ID', 'Product', 'First Name', 'Last Name', 'Email', 'Status', 'Checked In At', 'Created At']
        ];

        foreach ($attendees as $attendee) {
            $data[] = [
                $attendee->id ?? '',
                $attendee->short_id ?? '',
                $attendee->order_id ?? '',
                $attendee->event_id ?? '',
                $attendee->product ? $attendee->product->title : '',
                $attendee->first_name ?? '',
                $attendee->last_name ?? '',
                $attendee->email ?? '',
                $attendee->status ?? '',
                $attendee->checked_in_at ? (is_string($attendee->checked_in_at) ? $attendee->checked_in_at : $attendee->checked_in_at->toDateTimeString()) : '',
                $attendee->created_at ? (is_string($attendee->created_at) ? $attendee->created_at : $attendee->created_at->toDateTimeString()) : '',
            ];
        }

        $this->updateSheet($service, $spreadsheetId, 'Attendees', $data);
    }

    private function syncCheckIns(Google_Service_Sheets $service, string $spreadsheetId, int $eventId): void
    {
        $checkIns = AttendeeCheckIn::whereHas('attendee', function ($query) use ($eventId) {
            $query->where('event_id', $eventId);
        })->with('attendee.product')->get();
        $data = [
            ['ID', 'Check In List ID', 'Attendee ID', 'Product', 'Attendee Name', 'Attendee Email', 'Created At']
        ];

        foreach ($checkIns as $checkIn) {
            $data[] = [
                $checkIn->id ?? '',
                $checkIn->check_in_list_id ?? '',
                $checkIn->attendee_id ?? '',
                ($checkIn->attendee && $checkIn->attendee->product) ? $checkIn->attendee->product->title : '',
                $checkIn->attendee ? trim(($checkIn->attendee->first_name ?? '') . ' ' . ($checkIn->attendee->last_name ?? '')) : '',
                $checkIn->attendee ? ($checkIn->attendee->email ?? '') : '',
                $checkIn->created_at ? (is_string($checkIn->created_at) ? $checkIn->created_at : $checkIn->created_at->toDateTimeString()) : '',
            ];
        }

        $this->updateSheet($service, $spreadsheetId, 'Check-ins', $data);
    }

    private function updateSheet(Google_Service_Sheets $service, string $spreadsheetId, string $sheetName, array $data): void
    {
        // Add the sheet if it doesn't exist
        try {
            $service->spreadsheets_values->get($spreadsheetId, $sheetName . '!A1');
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() == 400) {
                // Sheet might not exist, try to create it
                $body = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                    'requests' => [
                        'addSheet' => [
                            'properties' => [
                                'title' => $sheetName
                            ]
                        ]
                    ]
                ]);
                try {
                    $service->spreadsheets->batchUpdate($spreadsheetId, $body);
                } catch (\Throwable $e2) {
                    Log::warning('Could not create sheet: ' . $e2->getMessage());
                }
            }
        }

        // Clear existing data
        $clearRequest = new Google_Service_Sheets_ClearValuesRequest();
        $service->spreadsheets_values->clear($spreadsheetId, $sheetName, $clearRequest);

        // Append new data
        $body = new Google_Service_Sheets_ValueRange([
            'values' => $data
        ]);
        $params = [
            'valueInputOption' => 'USER_ENTERED'
        ];

        $service->spreadsheets_values->append($spreadsheetId, $sheetName, $body, $params);
    }
}
