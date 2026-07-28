<?php

namespace App\Actions\Auth;

use App\Exceptions\RecentPasswordConfirmationRequiredException;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use Illuminate\Http\Request;

final readonly class RequireRecentPasswordConfirmationAction
{
    public function __construct(
        private RecentPasswordConfirmationGate $gate,
    ) {}

    public function __invoke(User $actor, Request $request): void
    {
        if (! $this->gate->isRecentlyConfirmed($actor, $request)) {
            throw new RecentPasswordConfirmationRequiredException;
        }
    }
}
