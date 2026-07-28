<?php

namespace App\Http\Requests\Clients;

use App\Http\Requests\AuthenticatedRequest;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use App\Policies\ClientContactPolicy;
use App\Policies\ClientPolicy;

final class DeleteClientContactRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $client = $this->route('client');
        $contact = $this->route('contact');

        return $actor instanceof User
            && $client instanceof Client
            && $contact instanceof ClientContact
            && app(ClientPolicy::class)->update($actor, $client)
            && app(ClientContactPolicy::class)->delete($actor, $contact);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
