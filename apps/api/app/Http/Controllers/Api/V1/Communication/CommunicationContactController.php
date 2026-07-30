<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\AddCommunicationIdentityRequest;
use App\Http\Requests\Communication\LinkCommunicationIdentityRequest;
use App\Http\Requests\Communication\ListCommunicationContactsRequest;
use App\Http\Requests\Communication\ListCommunicationSharedContentRequest;
use App\Http\Requests\Communication\StoreContactRequest;
use App\Http\Requests\Communication\UnlinkCommunicationIdentityRequest;
use App\Http\Requests\Communication\UpdateCommunicationContactRequest;
use App\Http\Requests\Communication\ViewCommunicationContactRequest;
use App\Http\Resources\Communication\CommunicationContactCollection;
use App\Http\Resources\Communication\CommunicationContactResource;
use App\Http\Resources\Communication\CommunicationIdentityLinkResource;
use App\Http\Resources\Communication\CommunicationIdentitySummaryResource;
use App\Http\Resources\Communication\CommunicationSharedContentResource;
use App\Models\CommunicationContact;
use App\Models\CommunicationIdentity;
use App\Services\Communication\CommunicationContactCanonicalizer;
use App\Services\Communication\CommunicationContactQuery;
use App\Services\Communication\CommunicationContactService;
use App\Services\Communication\Conversation\CommunicationSharedContentQuery;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CommunicationContactController extends Controller
{
    public function index(
        ListCommunicationContactsRequest $request,
        CommunicationContactQuery $query,
    ): JsonResponse {
        return $this->contactCollection($request, $query);
    }

    public function search(
        ListCommunicationContactsRequest $request,
        CommunicationContactQuery $query,
    ): JsonResponse {
        return $this->contactCollection($request, $query);
    }

    private function contactCollection(
        ListCommunicationContactsRequest $request,
        CommunicationContactQuery $query,
    ): JsonResponse {
        return (new CommunicationContactCollection(
            $query->paginate($request->filters(), $request->actor()),
        ))->response();
    }

    public function show(
        ViewCommunicationContactRequest $request,
        CommunicationContact $contact,
        CommunicationContactCanonicalizer $canonicalizer,
        CommunicationContactQuery $query,
    ): JsonResponse {
        $contact = $canonicalizer->contact($contact);
        $contact = $query->withProfilePictureProjection(CommunicationContact::query()->whereKey($contact->id), $request->actor())
            ->firstOrFail();

        return (new CommunicationContactResource(
            $contact->load([
                'identities.clientLinks.client',
                'identities.clientLinks.clientContact',
            ]),
        ))->response();
    }

    public function sharedContent(
        ListCommunicationSharedContentRequest $request,
        CommunicationContact $contact,
        CommunicationContactCanonicalizer $canonicalizer,
        CommunicationContactQuery $contacts,
        CommunicationSharedContentQuery $query,
    ): JsonResponse {
        $contact = $canonicalizer->contact($contact);
        $actor = $request->user();
        if ($actor === null) {
            abort(404);
        }
        try {
            $scope = $contacts->sharedContentScope($contact, $actor, $request->inboxId());
            $page = $query->paginate(
                (int) $contact->tenant_id,
                $scope['conversation_ids'],
                $request->category(),
                $request->limit(),
                $request->cursor(),
                'contact:'.$contact->id.':inboxes:'.implode(',', $scope['visible_inbox_ids']),
            );
        } catch (\InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json(['data' => CommunicationSharedContentResource::collection($page['data']), 'meta' => $page['meta']])
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function store(
        StoreContactRequest $request,
        CommunicationContactService $service,
    ): JsonResponse {
        return (new CommunicationContactResource(
            $service->create($request->payload()),
        ))->response()->setStatusCode(201);
    }

    public function update(
        UpdateCommunicationContactRequest $request,
        CommunicationContact $contact,
        CommunicationContactService $service,
        CommunicationContactQuery $query,
    ): JsonResponse {
        $updated = $service->update($contact, $request->payload());
        $projected = $query->withProfilePictureProjection(
            CommunicationContact::query()->whereKey($updated->id),
            $request->actor(),
        )->firstOrFail();

        return (new CommunicationContactResource(
            $projected->load([
                'identities.clientLinks.client',
                'identities.clientLinks.clientContact',
            ]),
        ))->response();
    }

    public function addIdentity(
        AddCommunicationIdentityRequest $request,
        CommunicationContact $contact,
        CommunicationContactService $service,
    ): JsonResponse {
        return (new CommunicationIdentitySummaryResource(
            $service->addIdentity($contact, $request->payload()),
        ))->response()->setStatusCode(201);
    }

    public function linkIdentity(
        LinkCommunicationIdentityRequest $request,
        CommunicationIdentity $identity,
        CommunicationContactService $service,
    ): JsonResponse {
        return (new CommunicationIdentityLinkResource(
            $service->linkIdentity($identity, $request->payload()),
        ))->response()->setStatusCode(201);
    }

    public function unlinkIdentity(
        UnlinkCommunicationIdentityRequest $request,
        CommunicationIdentity $identity,
        int $link,
        CommunicationContactService $service,
    ): Response {
        $service->unlinkIdentity($identity, $link);

        return response()->noContent();
    }
}
