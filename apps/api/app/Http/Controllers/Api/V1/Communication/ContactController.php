<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\AddIdentityRequest;
use App\Http\Requests\Communication\LinkIdentityRequest;
use App\Http\Requests\Communication\ListContactsRequest;
use App\Http\Requests\Communication\ListSharedContentRequest;
use App\Http\Requests\Communication\StoreContactRequest;
use App\Http\Requests\Communication\UnlinkIdentityRequest;
use App\Http\Requests\Communication\UpdateContactRequest;
use App\Http\Requests\Communication\ViewContactRequest;
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
        ListContactsRequest $request,
        ContactQuery $query,
    ): JsonResponse {
        return $this->contactCollection($request, $query);
    }

    public function search(
        ListContactsRequest $request,
        ContactQuery $query,
    ): JsonResponse {
        return $this->contactCollection($request, $query);
    }

    private function contactCollection(
        ListContactsRequest $request,
        ContactQuery $query,
    ): JsonResponse {
        return (new ContactCollection(
            $query->paginate($request->filters(), $request->actor()),
        ))->response();
    }

    public function show(
        ViewContactRequest $request,
        CommunicationContact $contact,
        ContactCanonicalizer $canonicalizer,
        ContactQuery $query,
    ): JsonResponse {
        $contact = $canonicalizer->contact($contact);
        $builder = CommunicationContact::query()->whereKey($contact->id);
        $contact = $request->inboxId() === null
            ? $query->withProfilePictureProjection($builder, $request->actor())->firstOrFail()
            : $query->withInboxContextProjection(
                $builder,
                $request->actor(),
                $request->inboxId(),
            )->firstOrFail();

        return (new ContactResource(
            $contact->load([
                'identities.clientLinks.client',
                'identities.clientLinks.clientContact',
            ]),
        ))->response();
    }

    public function sharedContent(
        ListSharedContentRequest $request,
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
        UpdateContactRequest $request,
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
        AddIdentityRequest $request,
        CommunicationContact $contact,
        ContactService $service,
    ): JsonResponse {
        return (new IdentitySummaryResource(
            $service->addIdentity($contact, $request->payload()),
        ))->response()->setStatusCode(201);
    }

    public function linkIdentity(
        LinkIdentityRequest $request,
        CommunicationIdentity $identity,
        ContactService $service,
    ): JsonResponse {
        return (new IdentityLinkResource(
            $service->linkIdentity($identity, $request->payload()),
        ))->response()->setStatusCode(201);
    }

    public function unlinkIdentity(
        UnlinkIdentityRequest $request,
        CommunicationIdentity $identity,
        int $link,
        ContactService $service,
    ): Response {
        $service->unlinkIdentity($identity, $link);

        return response()->noContent();
    }
}
