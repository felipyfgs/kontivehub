<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\UpdateCommunicationAutomationRecipientsAction;
use App\Actions\Communication\UpsertCommunicationAutomationPolicyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ListCommunicationAutomationPoliciesRequest;
use App\Http\Requests\Communication\UpdateCommunicationAutomationRecipientsRequest;
use App\Http\Requests\Communication\UpsertCommunicationAutomationPolicyRequest;
use App\Http\Requests\Communication\ViewCommunicationAutomationRecipientsRequest;
use App\Http\Resources\Communication\CommunicationAutomationPolicyCollection;
use App\Http\Resources\Communication\CommunicationAutomationPolicyResource;
use App\Http\Resources\Communication\CommunicationRecipientConfigurationResource;
use App\Models\Client;
use App\Services\Communication\Automation\CommunicationAutomationQuery;

final class CommunicationAutomationController extends Controller
{
    public function __construct(
        private readonly CommunicationAutomationQuery $query,
        private readonly UpsertCommunicationAutomationPolicyAction $upsertPolicy,
        private readonly UpdateCommunicationAutomationRecipientsAction $updateRecipients,
    ) {}

    public function index(
        ListCommunicationAutomationPoliciesRequest $request,
    ): CommunicationAutomationPolicyCollection {
        return new CommunicationAutomationPolicyCollection($this->query->index());
    }

    public function upsert(
        UpsertCommunicationAutomationPolicyRequest $request,
    ): CommunicationAutomationPolicyResource {
        return new CommunicationAutomationPolicyResource(
            $this->upsertPolicy->handle($request->policyData()),
        );
    }

    public function recipients(
        ViewCommunicationAutomationRecipientsRequest $request,
        Client $client,
    ): CommunicationRecipientConfigurationResource {
        return new CommunicationRecipientConfigurationResource(
            $this->query->recipients($client, $request->scope()),
        );
    }

    public function updateRecipients(
        UpdateCommunicationAutomationRecipientsRequest $request,
        Client $client,
    ): CommunicationRecipientConfigurationResource {
        return new CommunicationRecipientConfigurationResource(
            $this->updateRecipients->handle($client, $request->selection()),
        );
    }
}
