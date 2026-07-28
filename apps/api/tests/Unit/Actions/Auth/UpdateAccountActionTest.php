<?php

namespace Tests\Unit\Actions\Auth;

use App\Actions\Auth\UpdateAccountAction;
use App\DTO\Auth\AccountIdentityData;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use RuntimeException;
use Tests\TestCase;

final class UpdateAccountActionTest extends TestCase
{
    public function test_does_not_report_success_when_the_model_cancels_persistence(): void
    {
        $user = new class extends User
        {
            public function save(array $options = []): bool
            {
                return false;
            }
        };
        $user->forceFill([
            'id' => 123,
            'name' => 'Nome anterior',
            'email' => 'anterior@example.test',
        ])->syncOriginal();

        $action = new UpdateAccountAction(app(AuditLogger::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Não foi possível persistir a atualização da conta.');

        $action($user, new AccountIdentityData(
            name: 'Nome novo',
            email: 'novo@example.test',
        ));
    }
}
