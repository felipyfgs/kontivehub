<?php

namespace App\Http\Requests\Clients;

use App\Http\Requests\AuthenticatedRequest;
use App\Models\Client;
use App\Models\User;
use App\Policies\ClientPolicy;

final class ShowClientRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $client = $this->route('client');

        return $actor instanceof User
            && $client instanceof Client
            && app(ClientPolicy::class)->view($actor, $client);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
