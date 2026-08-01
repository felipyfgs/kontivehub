<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\UpdateAutomationRecipientsAction;
use App\Actions\Communication\UpsertAutomationPolicyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ListAutomationPoliciesRequest;
use App\Http\Requests\Communication\UpdateAutomationRecipientsRequest;
use App\Http\Requests\Communication\UpsertAutomationPolicyRequest;
use App\Http\Requests\Communication\ViewAutomationRecipientsRequest;
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
        ListAutomationPoliciesRequest $request,
    ): AutomationPolicyCollection {
        return new AutomationPolicyCollection($this->query->index());
    }

    public function upsert(
        UpsertAutomationPolicyRequest $request,
    ): AutomationPolicyResource {
        return new AutomationPolicyResource(
            $this->upsertPolicy->handle($request->policyData()),
        );
    }

    public function recipients(
        ViewAutomationRecipientsRequest $request,
        Client $client,
    ): RecipientConfigurationResource {
        return new RecipientConfigurationResource(
            $this->query->recipients($client, $request->scope()),
        );
    }

    public function updateRecipients(
        UpdateAutomationRecipientsRequest $request,
        Client $client,
    ): RecipientConfigurationResource {
        return new RecipientConfigurationResource(
            $this->updateRecipients->handle($client, $request->selection()),
        );
    }
}
