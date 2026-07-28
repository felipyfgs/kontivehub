<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationLabel;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

final class ManageCommunicationLabelRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $label = $this->route('label');

        return $actor instanceof User
            && $label instanceof CommunicationLabel
            && app(CommunicationAccess::class)->canManage($actor, $label);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
