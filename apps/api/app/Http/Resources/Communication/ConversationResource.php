<?php

namespace App\Http\Resources\Communication;

use App\Enums\Communication\ProfilePictureState;
use App\Models\User;
use App\Services\Communication\Contact\ConversationDisplayNameResolver;
use App\Services\Communication\Contact\IdentityPhonePresenter;
use App\Services\Communication\Contact\ProfilePictureUrlResolver;
use App\Services\Communication\Conversation\ConversationPreviewBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $display = app(ConversationDisplayNameResolver::class)->resolve($this->resource);
        $preview = $this->relationLoaded('latestMessage')
            ? app(ConversationPreviewBuilder::class)->fromMessage($this->latestMessage)
            : null;
        $phonePresenter = app(IdentityPhonePresenter::class);
        $actor = $request->user();

        return [
            'id' => $this->id,
            'inbox_id' => $this->inbox_id,
            'status' => $this->status?->value ?? $this->status,
            'work_department_id' => $this->work_department_id,
            'assignee_membership_id' => $this->assignee_membership_id,
            'priority' => (int) $this->priority,
            'snoozed_until' => $this->snoozed_until?->toIso8601String(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'lock_version' => (int) $this->lock_version,
            'messages_count' => $this->whenCounted('messages'),
            'unread_count' => (int) ($this->unread_count ?? 0),
            'first_unread_message_id' => $this->first_unread_message_id !== null
                ? (int) $this->first_unread_message_id
                : null,
            'read_state' => [
                'version' => (int) ($this->readState?->version ?? 0),
                'last_read_through_message_id' => $this->readState?->last_read_through_message_id !== null
                    ? (int) $this->readState->last_read_through_message_id
                    : null,
            ],
            'display_name' => $display['display_name'],
            'display_name_source' => $display['display_name_source'],
            'display_title' => $display['display_name'],
            'display_title_source' => $display['display_name_source'],
            'secondary_title' => $display['secondary_context'],
            'preview' => $preview,
            'contact' => $this->whenLoaded('identity', function () use ($phonePresenter, $actor): array {
                $phone = $phonePresenter->present(
                    $this->identity,
                    $this->identity->relationLoaded('contact') ? $this->identity->contact : null,
                    $actor instanceof User ? $actor : null,
                );

                return [
                    'id' => $this->identity->contact_id,
                    'name' => $this->identity->relationLoaded('contact') ? $this->identity->contact?->name : null,
                    'is_provisional' => $this->identity->relationLoaded('contact')
                        ? (bool) $this->identity->contact?->is_provisional
                        : null,
                    'address_masked' => $this->identity->address_masked,
                    'phone' => $phone,
                    // Alias : preserva shape, mas nunca devolve o endereço técnico bruto.
                    'address' => $phone,
                    'profile_picture_url' => app(ProfilePictureUrlResolver::class)->forConversation($this->resource),
                    'profile_picture_state' => app(ProfilePictureUrlResolver::class)->stateForConversation($this->resource)
                        ?? ProfilePictureState::Unknown->value,
                ];
            }),
            'clients' => $this->whenLoaded('clients', fn () => $this->clients->map(fn ($client) => [
                'id' => $client->id,
                'name' => $client->display_name ?: $client->legal_name,
            ])->values()),
            'labels' => $this->whenLoaded('labels', fn () => $this->labels->map(fn ($label) => [
                'id' => $label->id,
                'name' => $label->name,
                'color' => $label->color,
            ])->values()),
            'last_message' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage
                ? new MessageResource($this->latestMessage)
                : null),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
