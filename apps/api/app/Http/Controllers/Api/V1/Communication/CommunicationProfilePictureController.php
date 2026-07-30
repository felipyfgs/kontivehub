<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\StreamCommunicationProfilePictureAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ViewCommunicationProfilePictureRequest;
use App\Models\CommunicationInboxIdentityProfile;
use Symfony\Component\HttpFoundation\Response;

final class CommunicationProfilePictureController extends Controller
{
    public function show(ViewCommunicationProfilePictureRequest $request, CommunicationInboxIdentityProfile $profile, int $version, StreamCommunicationProfilePictureAction $action): Response
    {
        $response = $action->execute($profile, $version, $request->header('If-None-Match'));
        abort_if($response === null, 404);

        return $response;
    }
}
