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
use Carbon\Carbon;

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
                $this->formatIstDate($order->created_at),
            ];
        }

        $this->updateSheet($service, $spreadsheetId, 'Orders', $data);

        $this->applyConditionalFormatting($service, $spreadsheetId, 'Orders', [
            'ABANDONED' => [0.5, 0.0, 0.5], // Purple
            'AWAITING_OFFLINE_PAYMENT' => [0.6, 0.4, 0.2], // Brown
            'COMPLETED' => [0.2, 0.6, 0.3], // Green
        ], 9);
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
                $this->formatIstDate($attendee->checked_in_at),
                $this->formatIstDate($attendee->created_at),
            ];
        }

        $this->updateSheet($service, $spreadsheetId, 'Attendees', $data);

        $this->applyConditionalFormatting($service, $spreadsheetId, 'Attendees', [
            'AWAITING_PAYMENT' => [0.8, 0.2, 0.2], // Red
            'ACTIVE' => [0.2, 0.6, 0.3], // Green
            'CANCELLED' => [0.8, 0.2, 0.2], // Red
        ], 8);
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
                $this->formatIstDate($checkIn->created_at),
            ];
        }

        $this->updateSheet($service, $spreadsheetId, 'Check-ins', $data);
    }

    private function formatIstDate($date): string
    {
        if (!$date) {
            return '';
        }

        try {
            if (is_string($date)) {
                $date = Carbon::parse($date);
            }

            if ($date instanceof \DateTimeInterface) {
                return Carbon::instance($date)
                    ->setTimezone('Asia/Kolkata')
                    ->format('d/m/Y h:i A');
            }
        } catch (\Throwable $e) {
            // Ignore parse errors and fallback
        }

        return is_string($date) ? $date : '';
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

    private function applyConditionalFormatting(Google_Service_Sheets $service, string $spreadsheetId, string $sheetName, array $rules, int $columnIndex): void
    {
        $spreadsheet = $service->spreadsheets->get($spreadsheetId);
        $sheet = null;
        foreach ($spreadsheet->getSheets() as $s) {
            if ($s->getProperties()->getTitle() === $sheetName) {
                $sheet = $s;
                break;
            }
        }
        
        if (!$sheet) {
            return;
        }

        $sheetId = $sheet->getProperties()->getSheetId();
        $requests = [];

        // Remove existing conditional formats for this column to avoid duplicates
        $formats = $sheet->getConditionalFormats();
        if ($formats) {
            for ($i = count($formats) - 1; $i >= 0; $i--) {
                $rule = $formats[$i];
                $ranges = $rule->getRanges();
                foreach ($ranges as $range) {
                    if ($range->getStartColumnIndex() === $columnIndex && $range->getEndColumnIndex() === ($columnIndex + 1)) {
                        $requests[] = new \Google_Service_Sheets_Request([
                            'deleteConditionalFormatRule' => [
                                'sheetId' => $sheetId,
                                'index' => $i
                            ]
                        ]);
                        break;
                    }
                }
            }
        }

        // Add new rules
        foreach ($rules as $value => $color) {
            $requests[] = new \Google_Service_Sheets_Request([
                'addConditionalFormatRule' => [
                    'rule' => [
                        'ranges' => [
                            [
                                'sheetId' => $sheetId,
                                'startRowIndex' => 1,
                                'startColumnIndex' => $columnIndex,
                                'endColumnIndex' => $columnIndex + 1
                            ]
                        ],
                        'booleanRule' => [
                            'condition' => [
                                'type' => 'TEXT_EQ',
                                'values' => [
                                    ['userEnteredValue' => $value]
                                ]
                            ],
                            'format' => [
                                'backgroundColor' => [
                                    'red' => $color[0],
                                    'green' => $color[1],
                                    'blue' => $color[2]
                                ],
                                'textFormat' => [
                                    'foregroundColor' => [
                                        'red' => 1,
                                        'green' => 1,
                                        'blue' => 1
                                    ],
                                    'bold' => true
                                ]
                            ]
                        ]
                    ],
                    'index' => 0
                ]
            ]);
        }

        if (!empty($requests)) {
            $body = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                'requests' => $requests
            ]);
            $service->spreadsheets->batchUpdate($spreadsheetId, $body);
        }
    }
}
