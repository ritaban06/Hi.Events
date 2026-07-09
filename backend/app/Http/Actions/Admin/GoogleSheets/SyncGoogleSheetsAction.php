<?php

namespace HiEvents\Http\Actions\Admin\GoogleSheets;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Jobs\Integrations\SyncGoogleSheetsJob;
use Illuminate\Http\JsonResponse;

class SyncGoogleSheetsAction extends BaseAction
{
    public function __invoke(): JsonResponse
    {
        $this->minimumAllowedRole(Role::SUPERADMIN);

        SyncGoogleSheetsJob::dispatch();

        return response()->json([
            'message' => 'Google Sheets sync job has been dispatched.',
        ]);
    }
}
