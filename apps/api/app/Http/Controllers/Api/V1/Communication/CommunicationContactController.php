<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\AddCommunicationIdentityRequest;
use App\Http\Requests\Communication\LinkCommunicationIdentityRequest;
use App\Http\Requests\Communication\ListCommunicationContactsRequest;
use App\Http\Requests\Communication\StoreContactRequest;
use App\Http\Requests\Communication\UnlinkCommunicationIdentityRequest;
use App\Http\Requests\Communication\UpdateCommunicationContactRequest;
use App\Http\Requests\Communication\ViewCommunicationContactRequest;
use App\Http\Resources\Communication\CommunicationContactCollection;
use App\Http\Resources\Communication\CommunicationContactResource;
use App\Http\Resources\Communication\CommunicationIdentityLinkResource;
use App\Http\Resources\Communication\CommunicationIdentitySummaryResource;
use App\Models\CommunicationContact;
use App\Models\CommunicationIdentity;
use App\Services\Communication\CommunicationContactCanonicalizer;
use App\Services\Communication\CommunicationContactQuery;
use App\Services\Communication\CommunicationContactService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CommunicationContactController extends Controller
{
    public function index(
        ListCommunicationContactsRequest $request,
        CommunicationContactQuery $query,
    ): JsonResponse {
        return (new CommunicationContactCollection(
            $query->paginate($request->filters()),
        ))->response();
    }

    public function show(
        ViewCommunicationContactRequest $request,
        CommunicationContact $contact,
        CommunicationContactCanonicalizer $canonicalizer,
    ): JsonResponse {
        $contact = $canonicalizer->contact($contact);

        return (new CommunicationContactResource(
            $contact->load([
                'identities.clientLinks.client',
                'identities.clientLinks.clientContact',
            ]),
        ))->response();
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
    ): JsonResponse {
        return (new CommunicationContactResource(
            $service->update($contact, $request->payload()),
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
