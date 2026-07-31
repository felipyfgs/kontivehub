<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationFlowRun;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class ViewCommunicationFlowRunRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $run = $this->route('run');

        return $actor instanceof User
            && $run instanceof CommunicationFlowRun
            && app(Access::class)->canViewFlows($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
