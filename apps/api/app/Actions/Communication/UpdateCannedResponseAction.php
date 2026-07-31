<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CannedResponseMutationData;
use App\Exceptions\CommunicationCannedResponseApiException;
use App\Models\CommunicationCannedResponse;
use App\Services\Communication\Canned\CannedResponseConflictMapper;
use App\Services\Communication\Events\EventRecorder;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class UpdateCannedResponseAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private EventRecorder $events,
        private CannedResponseConflictMapper $conflicts,
    ) {}

    public function handle(
        CommunicationCannedResponse $canned,
        CannedResponseMutationData $data,
    ): CommunicationCannedResponse {
        if ($data->lockVersion === null) {
            throw new LogicException('Versão esperada é obrigatória para atualizar resposta rápida.');
        }

        try {
            return DB::transaction(function () use ($canned, $data): CommunicationCannedResponse {
                $fresh = CommunicationCannedResponse::query()
                    ->whereKey($canned->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ((int) $fresh->lock_version !== $data->lockVersion) {
                    throw CommunicationCannedResponseApiException::versionConflict();
                }

                $fresh->fill([
                    'title' => $data->title,
                    'shortcut' => $data->shortcut,
                    'body_encrypted' => $data->body,
                    'is_active' => $data->isActive ?? (bool) $fresh->is_active,
                    'lock_version' => $data->lockVersion + 1,
                ])->save();

                $this->events->record((int) $fresh->tenant_id, 'CANNED_RESPONSE_UPDATED', [
                    'canned_response_id' => (int) $fresh->id,
                    'shortcut' => $fresh->shortcut,
                    'lock_version' => (int) $fresh->lock_version,
                    'is_active' => (bool) $fresh->is_active,
                ], actorMembershipId: $this->currentTenant->realMembership()?->id);

                return $fresh;
            });
        } catch (QueryException $error) {
            $this->conflicts->throwIfShortcutConflict($error);

            throw $error;
        }
    }
}
