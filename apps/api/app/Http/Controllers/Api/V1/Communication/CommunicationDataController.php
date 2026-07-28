<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\ExportCommunicationContactAction;
use App\Actions\Communication\PurgeCommunicationContactAction;
use App\Actions\Communication\StreamCommunicationAttachmentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ManageCommunicationContactDataRequest;
use App\Http\Requests\Communication\SyncCommunicationEventsRequest;
use App\Http\Requests\Communication\ViewCommunicationAttachmentRequest;
use App\Http\Resources\Communication\CommunicationContactPurgeResource;
use App\Http\Resources\Communication\CommunicationEventSyncResource;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationContact;
use App\Services\Communication\Events\CommunicationEventSyncQuery;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CommunicationDataController extends Controller
{
    public function sync(
        SyncCommunicationEventsRequest $request,
        CommunicationEventSyncQuery $query,
    ): JsonResponse {
        return (new CommunicationEventSyncResource(
            $query->execute($request->actor(), $request->filters()),
        ))->response()->header('Cache-Control', 'private, no-store');
    }

    public function downloadAttachment(
        ViewCommunicationAttachmentRequest $request,
        CommunicationAttachment $attachment,
        StreamCommunicationAttachmentAction $action,
    ): StreamedResponse {
        return $action->download($attachment);
    }

    public function previewAttachment(
        ViewCommunicationAttachmentRequest $request,
        CommunicationAttachment $attachment,
        StreamCommunicationAttachmentAction $action,
    ): StreamedResponse {
        return $action->preview($attachment);
    }

    public function exportContact(
        ManageCommunicationContactDataRequest $request,
        CommunicationContact $contact,
        ExportCommunicationContactAction $action,
    ): StreamedResponse {
        return $action->execute($contact);
    }

    public function purgeContact(
        ManageCommunicationContactDataRequest $request,
        CommunicationContact $contact,
        PurgeCommunicationContactAction $action,
    ): JsonResponse {
        return (new CommunicationContactPurgeResource(
            $action->execute($contact),
        ))->response();
    }
}
