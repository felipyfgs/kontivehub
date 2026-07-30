<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationInboxIdentityProfile;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

final class ViewCommunicationProfilePictureRequest extends CommunicationRequest
{
    protected function failedAuthorization(): void
    {
        abort(404);
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        $profile = $this->route('profile');

        return $actor instanceof User && $profile instanceof CommunicationInboxIdentityProfile
            && $profile->inbox()->exists()
            && app(CommunicationAccess::class)->canView($actor, $profile->inbox()->first());
    }

    public function rules(): array
    {
        return [];
    }
}
