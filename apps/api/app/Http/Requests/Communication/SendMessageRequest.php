<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationMessageCreationData;
use App\DTO\Communication\CommunicationMessageUploadData;
use App\Enums\Communication\MessageKind;
use App\Models\CommunicationConversation;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SendMessageRequest extends CommunicationRequest
{
    protected function prepareCommunicationValidation(): void
    {
        foreach (['ptt', 'gif', 'internal_note'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => $this->boolean($field)]);
            }
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        $conversation = $this->route('conversation');
        if (! $actor instanceof User || ! $conversation instanceof CommunicationConversation) {
            return false;
        }

        $inbox = $conversation->inbox()->first();

        return $inbox !== null
            && app(CommunicationAccess::class)->canReply($actor, $inbox);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'required_without_all:file,location,contact,poll,interactive', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 4096) {
                    $fail('O campo '.$attribute.' não pode ter mais que 4096 bytes.');
                }
            }],
            'file' => [
                'nullable',
                'file',
                'max:'.max(1, (int) ceil(((int) config('communication.media.max_bytes', 20_971_520)) / 1024)),
                'mimetypes:image/jpeg,image/png,image/webp,audio/ogg,audio/mpeg,audio/mp4,audio/webm,video/mp4,video/webm,application/pdf,text/plain,application/zip',
            ],
            'kind' => ['nullable', Rule::in([
                'TEXT', 'IMAGE', 'AUDIO', 'VIDEO', 'DOCUMENT', 'STICKER',
                'LOCATION', 'CONTACT', 'POLL', 'INTERACTIVE', 'UNSUPPORTED',
            ])],
            'ptt' => ['sometimes', 'boolean'],
            'gif' => ['sometimes', 'boolean'],
            'link_preview' => ['nullable', 'array:'.implode(',', ['url', 'title', 'description'])],
            'link_preview.url' => ['required_with:link_preview', 'url:http,https', 'max:2048'],
            'link_preview.title' => ['nullable', 'string', 'max:512'],
            'link_preview.description' => ['nullable', 'string', 'max:2048'],
            'location' => ['nullable', 'array:latitude,longitude,name,address'],
            'location.latitude' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.longitude' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.name' => ['nullable', 'string', 'max:512'],
            'location.address' => ['nullable', 'string', 'max:2048'],
            'contact' => ['nullable', 'array:display_name,vcard'],
            'contact.display_name' => ['required_with:contact', 'string', 'max:512'],
            'contact.vcard' => ['required_with:contact', 'string', 'max:65536'],
            'poll' => ['nullable', 'array:name,options,selectable_options'],
            'poll.name' => ['required_with:poll', 'string', 'max:1024'],
            'poll.options' => ['required_with:poll', 'array', 'min:2', 'max:12'],
            'poll.options.*' => ['required', 'string', 'distinct', 'max:512'],
            'poll.selectable_options' => ['required_with:poll', 'integer', 'min:1', 'max:12'],
            'interactive' => ['nullable', 'array:mode,title,description,options'],
            'interactive.mode' => ['required_with:interactive', Rule::in(['BUTTONS', 'LIST'])],
            'interactive.title' => ['nullable', 'string', 'max:512'],
            'interactive.description' => ['nullable', 'string', 'max:2048'],
            'interactive.options' => ['required_with:interactive', 'array', 'min:1', 'max:20'],
            'interactive.options.*' => ['required', 'string', 'distinct', 'max:512'],
            'reply_to_message_id' => ['nullable', 'integer', 'min:1'],
            'receipt_message_id' => ['nullable', 'integer', 'min:1', 'prohibited_if:internal_note,true'],
            'internal_note' => ['sometimes', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/'],
        ];
    }

    public function messageData(): CommunicationMessageCreationData
    {
        $validated = $this->validated();
        $richPayload = array_filter([
            'link_preview' => $validated['link_preview'] ?? null,
            'location' => $validated['location'] ?? null,
            'contact' => $validated['contact'] ?? null,
            'poll' => $validated['poll'] ?? null,
            'interactive' => $validated['interactive'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        return new CommunicationMessageCreationData(
            body: trim((string) ($validated['body'] ?? '')),
            internalNote: (bool) ($validated['internal_note'] ?? false),
            requestedKind: isset($validated['kind'])
                ? MessageKind::from((string) $validated['kind'])
                : null,
            ptt: (bool) ($validated['ptt'] ?? false),
            gif: (bool) ($validated['gif'] ?? false),
            richPayload: $richPayload,
            replyToMessageId: isset($validated['reply_to_message_id'])
                ? (int) $validated['reply_to_message_id']
                : null,
            idempotencyKey: isset($validated['idempotency_key'])
                ? (string) $validated['idempotency_key']
                : null,
            upload: $this->uploadData(),
            receiptMessageId: isset($validated['receipt_message_id'])
                ? (int) $validated['receipt_message_id']
                : null,
        );
    }

    private function uploadData(): ?CommunicationMessageUploadData
    {
        $upload = $this->file('file');
        if (! $upload instanceof UploadedFile) {
            return null;
        }

        $path = $upload->getRealPath();
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'file' => 'Arquivo inválido.',
            ]);
        }

        return new CommunicationMessageUploadData(
            path: $path,
            originalName: $upload->getClientOriginalName(),
            detectedMime: (string) $upload->getMimeType(),
            clientMime: (string) $upload->getClientMimeType(),
        );
    }
}
