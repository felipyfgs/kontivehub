<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\UpdateAutomationRecipientsAction;
use App\Actions\Communication\UpsertAutomationPolicyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ListCommunicationAutomationPoliciesRequest;
use App\Http\Requests\Communication\UpdateCommunicationAutomationRecipientsRequest;
use App\Http\Requests\Communication\UpsertCommunicationAutomationPolicyRequest;
use App\Http\Requests\Communication\ViewCommunicationAutomationRecipientsRequest;
use App\Http\Resources\Communication\AutomationPolicyCollection;
use App\Http\Resources\Communication\AutomationPolicyResource;
use App\Http\Resources\Communication\RecipientConfigurationResource;
use App\Models\Client;
use App\Services\Communication\Automation\AutomationQuery;

final class AutomationController extends Controller
{
    public function __construct(
        private readonly AutomationQuery $query,
        private readonly UpsertAutomationPolicyAction $upsertPolicy,
        private readonly UpdateAutomationRecipientsAction $updateRecipients,
    ) {}

    public function index(
        ListCommunicationAutomationPoliciesRequest $request,
    ): AutomationPolicyCollection {
        return new AutomationPolicyCollection($this->query->index());
    }

    public function upsert(
        UpsertCommunicationAutomationPolicyRequest $request,
    ): AutomationPolicyResource {
        return new AutomationPolicyResource(
            $this->upsertPolicy->handle($request->policyData()),
        );
    }

    public function recipients(
        ViewCommunicationAutomationRecipientsRequest $request,
        Client $client,
    ): RecipientConfigurationResource {
        return new RecipientConfigurationResource(
            $this->query->recipients($client, $request->scope()),
        );
    }

    public function updateRecipients(
        UpdateCommunicationAutomationRecipientsRequest $request,
        Client $client,
    ): RecipientConfigurationResource {
        return new RecipientConfigurationResource(
            $this->updateRecipients->handle($client, $request->selection()),
        );
    }
}
