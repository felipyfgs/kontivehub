<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CannedResponseRenderData;
use App\DTO\Communication\CannedResponseRenderResult;
use App\Models\CommunicationCannedResponse;
use App\Models\CommunicationConversation;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use App\Services\Communication\Canned\CannedResponseRenderer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class RenderCannedResponseAction
{
    public function __construct(
        private Access $access,
        private CannedResponseRenderer $renderer,
    ) {}

    public function handle(
        CommunicationCannedResponse $canned,
        CannedResponseRenderData $data,
        User $actor,
    ): CannedResponseRenderResult {
        if (! $canned->is_active) {
            throw new NotFoundHttpException;
        }

        $conversation = CommunicationConversation::query()
            ->with('inbox')
            ->findOrFail($data->conversationId);
        $this->access->assertView($actor, $conversation->inbox);

        return new CannedResponseRenderResult(
            cannedResponseId: (int) $canned->id,
            conversationId: (int) $conversation->id,
            body: $this->renderer->render($canned, $conversation, $actor),
        );
    }
}
