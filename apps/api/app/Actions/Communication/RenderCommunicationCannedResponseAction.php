<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationCannedResponseRenderData;
use App\DTO\Communication\CommunicationCannedResponseRenderResult;
use App\Models\CommunicationCannedResponse;
use App\Models\CommunicationConversation;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\Canned\CannedResponseRenderer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class RenderCommunicationCannedResponseAction
{
    public function __construct(
        private CommunicationAccess $access,
        private CannedResponseRenderer $renderer,
    ) {}

    public function handle(
        CommunicationCannedResponse $canned,
        CommunicationCannedResponseRenderData $data,
        User $actor,
    ): CommunicationCannedResponseRenderResult {
        if (! $canned->is_active) {
            throw new NotFoundHttpException;
        }

        $conversation = CommunicationConversation::query()
            ->with('inbox')
            ->findOrFail($data->conversationId);
        $this->access->assertView($actor, $conversation->inbox);

        return new CommunicationCannedResponseRenderResult(
            cannedResponseId: (int) $canned->id,
            conversationId: (int) $conversation->id,
            body: $this->renderer->render($canned, $conversation, $actor),
        );
    }
}
