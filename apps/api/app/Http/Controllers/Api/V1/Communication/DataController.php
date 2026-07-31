<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\ExportContactAction;
use App\Actions\Communication\PurgeContactAction;
use App\Actions\Communication\StreamAttachmentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ManageCommunicationContactDataRequest;
use App\Http\Requests\Communication\SyncCommunicationEventsRequest;
use App\Http\Requests\Communication\ViewCommunicationAttachmentRequest;
use App\Http\Resources\Communication\ContactPurgeResource;
use App\Http\Resources\Communication\EventSyncResource;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationContact;
use App\Services\Communication\Events\EventSyncQuery;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DataController extends Controller
{
    public function sync(
        SyncCommunicationEventsRequest $request,
        EventSyncQuery $query,
    ): JsonResponse {
        return (new EventSyncResource(
            $query->execute($request->actor(), $request->filters()),
        ))->response()->header('Cache-Control', 'private, no-store');
    }

    public function downloadAttachment(
        ViewCommunicationAttachmentRequest $request,
        CommunicationAttachment $attachment,
        StreamAttachmentAction $action,
    ): StreamedResponse {
        return $action->download($attachment);
    }

    public function previewAttachment(
        ViewCommunicationAttachmentRequest $request,
        CommunicationAttachment $attachment,
        StreamAttachmentAction $action,
    ): StreamedResponse {
        return $action->preview($attachment);
    }

    public function exportContact(
        ManageCommunicationContactDataRequest $request,
        CommunicationContact $contact,
        ExportContactAction $action,
    ): StreamedResponse {
        return $action->execute($contact);
    }

    public function purgeContact(
        ManageCommunicationContactDataRequest $request,
        CommunicationContact $contact,
        PurgeContactAction $action,
    ): JsonResponse {
        return (new ContactPurgeResource(
            $action->execute($contact),
        ))->response();
    }
}
