<?php

namespace App\Actions\Platform;

use App\DTO\Platform\PlatformOwnerUpdateData;
use App\Exceptions\PlatformOwnerApiException;
use App\Models\User;
use App\Services\Platform\PlatformOwnerException;
use App\Services\Platform\PlatformOwnerService;

final readonly class UpdatePlatformOwnerAction
{
    public function __construct(
        private PlatformOwnerService $owners,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(PlatformOwnerUpdateData $data, User $actor): array
    {
        try {
            $result = $this->owners->updateOwner($data->toArray(), $actor);

            return $this->owners->sanitize($result['membership']);
        } catch (PlatformOwnerException $error) {
            throw PlatformOwnerApiException::fromDomain($error);
        }
    }
}
