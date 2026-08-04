<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\ExportContactAction;
use App\Actions\Communication\PurgeContactAction;
use App\Actions\Communication\StreamAttachmentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ManageContactDataRequest;
use App\Http\Requests\Communication\SyncEventsRequest;
use App\Http\Requests\Communication\ViewAttachmentRequest;
use App\Http\Resources\Communication\ContactPurgeResource;
use App\Http\Resources\Communication\EventSyncResource;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationContact;
use App\Services\Communication\Events\EventSyncQuery;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DataController extends Controller
{
    public function sync(
        SyncEventsRequest $request,
        EventSyncQuery $query,
    ): JsonResponse {
        return (new EventSyncResource(
            $query->execute($request->actor(), $request->filters()),
        ))->response()->header('Cache-Control', 'private, no-store');
    }

    public function downloadAttachment(
        ViewAttachmentRequest $request,
        CommunicationAttachment $attachment,
        StreamAttachmentAction $action,
    ): Response {
        return $action->download($attachment, $request);
    }

    public function previewAttachment(
        ViewAttachmentRequest $request,
        CommunicationAttachment $attachment,
        StreamAttachmentAction $action,
    ): Response {
        return $action->preview($attachment, $request);
    }

    public function exportContact(
        ManageContactDataRequest $request,
        CommunicationContact $contact,
        ExportContactAction $action,
    ): StreamedResponse {
        return $action->execute($contact);
    }

    public function purgeContact(
        ManageContactDataRequest $request,
        CommunicationContact $contact,
        PurgeContactAction $action,
    ): JsonResponse {
        return (new ContactPurgeResource(
            $action->execute($contact),
        ))->response();
    }
}
