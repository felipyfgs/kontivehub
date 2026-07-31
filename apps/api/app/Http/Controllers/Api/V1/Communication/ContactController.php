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
use App\Http\Resources\Communication\ContactCollection;
use App\Http\Resources\Communication\ContactResource;
use App\Http\Resources\Communication\IdentityLinkResource;
use App\Http\Resources\Communication\IdentitySummaryResource;
use App\Http\Resources\Communication\SharedContentResource;
use App\Models\CommunicationContact;
use App\Models\CommunicationIdentity;
use App\Services\Communication\ContactCanonicalizer;
use App\Services\Communication\ContactQuery;
use App\Services\Communication\ContactService;
use App\Services\Communication\Conversation\SharedContentQuery;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ContactController extends Controller
{
    public function index(
        ListCommunicationContactsRequest $request,
        ContactQuery $query,
    ): JsonResponse {
        return $this->contactCollection($request, $query);
    }

    public function search(
        ListCommunicationContactsRequest $request,
        ContactQuery $query,
    ): JsonResponse {
        return $this->contactCollection($request, $query);
    }

    private function contactCollection(
        ListCommunicationContactsRequest $request,
        ContactQuery $query,
    ): JsonResponse {
        return (new ContactCollection(
            $query->paginate($request->filters(), $request->actor()),
        ))->response();
    }

    public function show(
        ViewCommunicationContactRequest $request,
        CommunicationContact $contact,
        ContactCanonicalizer $canonicalizer,
        ContactQuery $query,
    ): JsonResponse {
        $contact = $canonicalizer->contact($contact);
        $contact = $query->withProfilePictureProjection(CommunicationContact::query()->whereKey($contact->id), $request->actor())
            ->firstOrFail();

        return (new ContactResource(
            $contact->load([
                'identities.clientLinks.client',
                'identities.clientLinks.clientContact',
            ]),
        ))->response();
    }

    public function sharedContent(
        ListCommunicationSharedContentRequest $request,
        CommunicationContact $contact,
        ContactCanonicalizer $canonicalizer,
        ContactQuery $contacts,
        SharedContentQuery $query,
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

        return response()->json(['data' => SharedContentResource::collection($page['data']), 'meta' => $page['meta']])
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function store(
        StoreContactRequest $request,
        ContactService $service,
    ): JsonResponse {
        return (new ContactResource(
            $service->create($request->payload()),
        ))->response()->setStatusCode(201);
    }

    public function update(
        UpdateCommunicationContactRequest $request,
        CommunicationContact $contact,
        ContactService $service,
        ContactQuery $query,
    ): JsonResponse {
        $updated = $service->update($contact, $request->payload());
        $projected = $query->withProfilePictureProjection(
            CommunicationContact::query()->whereKey($updated->id),
            $request->actor(),
        )->firstOrFail();

        return (new ContactResource(
            $projected->load([
                'identities.clientLinks.client',
                'identities.clientLinks.clientContact',
            ]),
        ))->response();
    }

    public function addIdentity(
        AddCommunicationIdentityRequest $request,
        CommunicationContact $contact,
        ContactService $service,
    ): JsonResponse {
        return (new IdentitySummaryResource(
            $service->addIdentity($contact, $request->payload()),
        ))->response()->setStatusCode(201);
    }

    public function linkIdentity(
        LinkCommunicationIdentityRequest $request,
        CommunicationIdentity $identity,
        ContactService $service,
    ): JsonResponse {
        return (new IdentityLinkResource(
            $service->linkIdentity($identity, $request->payload()),
        ))->response()->setStatusCode(201);
    }

    public function unlinkIdentity(
        UnlinkCommunicationIdentityRequest $request,
        CommunicationIdentity $identity,
        int $link,
        ContactService $service,
    ): Response {
        $service->unlinkIdentity($identity, $link);

        return response()->noContent();
    }
}
