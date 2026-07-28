<?php

namespace App\Actions\Platform;

use App\Exceptions\PlatformOwnerApiException;
use App\Services\Platform\PlatformOwnerException;
use App\Services\Platform\PlatformOwnerService;

final readonly class ShowPlatformOwnerAction
{
    public function __construct(
        private PlatformOwnerService $owners,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        try {
            return $this->owners->sanitize($this->owners->requireMembership());
        } catch (PlatformOwnerException $error) {
            throw PlatformOwnerApiException::fromDomain($error);
        }
    }
}
