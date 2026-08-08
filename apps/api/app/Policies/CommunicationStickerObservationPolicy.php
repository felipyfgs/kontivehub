<?php

namespace App\Policies;

use App\Models\CommunicationStickerObservation;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final readonly class CommunicationStickerObservationPolicy
{
    public function __construct(private Access $access) {}

    public function view(User $user, CommunicationStickerObservation $sticker): bool
    {
        $inbox = $sticker->inbox()->first();

        return $inbox !== null && $this->access->canView($user, $inbox);
    }

    public function update(User $user, CommunicationStickerObservation $sticker): bool
    {
        $inbox = $sticker->inbox()->first();

        return $inbox !== null && $this->access->canReply($user, $inbox);
    }

    public function delete(User $user, CommunicationStickerObservation $sticker): bool
    {
        return $this->update($user, $sticker);
    }
}
