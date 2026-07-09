<?php

namespace HiEvents\Http\Actions\Events\GoogleSheets;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Jobs\Integrations\SyncGoogleSheetsJob;
use Illuminate\Http\JsonResponse;

class SyncGoogleSheetsAction extends BaseAction
{
    public function __invoke(int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        SyncGoogleSheetsJob::dispatch($eventId);

        return response()->json([
            'message' => 'Google Sheets sync job has been dispatched.',
        ]);
    }
}
