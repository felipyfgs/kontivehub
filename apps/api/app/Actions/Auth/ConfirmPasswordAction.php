<?php

namespace App\Actions\Auth;

use App\DTO\Auth\PasswordConfirmationData;
use App\DTO\Auth\PasswordConfirmationResult;
use App\Exceptions\InvalidPasswordException;
use App\Models\User;
use App\Services\Auth\Exceptions\PasswordConfirmationFailedException;
use App\Services\Auth\RecentPasswordConfirmationGate;
use Illuminate\Http\Request;

final readonly class ConfirmPasswordAction
{
    public function __construct(
        private RecentPasswordConfirmationGate $gate,
    ) {}

    public function __invoke(
        User $user,
        PasswordConfirmationData $data,
        Request $request,
    ): PasswordConfirmationResult {
        try {
            $this->gate->confirmWithPassword($user, $data->password, $request);
        } catch (PasswordConfirmationFailedException) {
            throw new InvalidPasswordException;
        }

        return new PasswordConfirmationResult(
            windowMinutes: $this->gate->windowMinutes(),
            secondsRemaining: $this->gate->secondsRemaining($request, $user),
        );
    }
}
