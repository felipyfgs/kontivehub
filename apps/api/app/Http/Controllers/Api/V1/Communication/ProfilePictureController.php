<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\StreamProfilePictureAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ViewProfilePictureRequest;
use App\Models\CommunicationInboxIdentityProfile;
use Symfony\Component\HttpFoundation\Response;

final class ProfilePictureController extends Controller
{
    public function show(ViewProfilePictureRequest $request, CommunicationInboxIdentityProfile $profile, int $version, StreamProfilePictureAction $action): Response
    {
        $response = $action->execute($profile, $version, $request->header('If-None-Match'));
        abort_if($response === null, 404);

        return $response;
    }
}
