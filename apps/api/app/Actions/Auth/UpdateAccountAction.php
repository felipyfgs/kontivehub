<?php

namespace App\Actions\Auth;

use App\DTO\Auth\AccountIdentityData;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use RuntimeException;

final readonly class UpdateAccountAction
{
    public function __construct(
        private AuditLogger $audit,
    ) {}

    public function __invoke(User $user, AccountIdentityData $data): User
    {
        $user->fill([
            'name' => $data->name,
            'email' => $data->email,
        ]);

        $changed = array_keys($user->getDirty());
        if (! $user->save()) {
            throw new RuntimeException('Não foi possível persistir a atualização da conta.');
        }

        $this->audit->record(
            action: 'account.profile_updated',
            subject: $user,
            context: ['fields' => $changed],
            userId: $user->id,
        );

        return $user;
    }
}
