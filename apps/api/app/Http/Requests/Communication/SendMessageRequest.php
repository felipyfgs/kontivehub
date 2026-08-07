<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\MessageCreationData;
use App\DTO\Communication\MessageUploadData;
use App\Enums\Communication\MessageKind;
use App\Models\CommunicationConversation;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use App\Services\Communication\StickerLibrary\LibraryStickerSendResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SendMessageRequest extends TenantScopedRequest
{
    private ?string $libraryStickerTempPath = null;

    protected function prepareScopedValidation(): void
    {
        foreach (['ptt', 'gif', 'ptv', 'view_once', 'internal_note'] as $field) {
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
            && app(Access::class)->canReply($actor, $inbox);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'required_without_all:file,library_sticker_id,location,contact,contacts,poll,event,interactive', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 4096) {
                    $fail('O campo '.$attribute.' não pode ter mais que 4096 bytes.');
                }
            }],
            'file' => [
                'nullable',
                'file',
                'prohibits:library_sticker_id',
                'max:'.max(1, (int) ceil(((int) config('communication.media.max_bytes', 20_971_520)) / 1024)),
                'mimetypes:image/jpeg,image/png,image/webp,audio/ogg,audio/mpeg,audio/mp4,audio/webm,video/mp4,video/webm,application/pdf,text/plain,application/zip',
            ],
            'library_sticker_id' => [
                'nullable',
                'string',
                'regex:/^[A-Za-z0-9_-]{8,128}$/',
                'prohibits:file',
                Rule::prohibitedIf(fn (): bool => (bool) $this->boolean('internal_note')),
            ],
            'kind' => ['nullable', Rule::in([
                'TEXT', 'IMAGE', 'AUDIO', 'VIDEO', 'DOCUMENT', 'STICKER',
                'LOCATION', 'CONTACT', 'POLL', 'EVENT', 'INTERACTIVE', 'UNSUPPORTED',
            ])],
            'ptt' => ['sometimes', 'boolean'],
            'gif' => ['sometimes', 'boolean'],
            'ptv' => ['sometimes', 'boolean'],
            'view_once' => ['sometimes', 'boolean'],
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
            'contacts' => ['nullable', 'array', 'min:1', 'max:10', Rule::prohibitedIf(fn (): bool => $this->filled('contact'))],
            'contacts.*' => ['required', 'array:display_name,vcard'],
            'contacts.*.display_name' => ['required', 'string', 'max:512'],
            'contacts.*.vcard' => ['required', 'string', 'max:65536'],
            'poll' => ['nullable', 'array:name,options,selectable_options'],
            'poll.name' => ['required_with:poll', 'string', 'max:1024'],
            'poll.options' => ['required_with:poll', 'array', 'min:2', 'max:12'],
            'poll.options.*' => ['required', 'string', 'distinct', 'max:512'],
            'poll.selectable_options' => ['required_with:poll', 'integer', 'min:1', 'max:12'],
            'event' => ['nullable', 'array:title,description,start_at,end_at,timezone,location_name,location_address,participation_enabled'],
            'event.title' => ['required_with:event', 'string', 'max:512'],
            'event.description' => ['nullable', 'string', 'max:2048'],
            'event.start_at' => ['required_with:event', 'date_format:Y-m-d\\TH:i:sP'],
            'event.end_at' => ['nullable', 'date_format:Y-m-d\\TH:i:sP', 'after:event.start_at'],
            'event.timezone' => ['required_with:event', 'timezone', 'max:64'],
            'event.location_name' => ['nullable', 'string', 'max:512'],
            'event.location_address' => ['nullable', 'string', 'max:2048'],
            'event.participation_enabled' => ['nullable', 'boolean'],
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

    public function messageData(): MessageCreationData
    {
        $validated = $this->validated();
        $libraryStickerId = isset($validated['library_sticker_id'])
            ? (string) $validated['library_sticker_id']
            : null;
        $richPayload = array_filter([
            'link_preview' => $validated['link_preview'] ?? null,
            'location' => $validated['location'] ?? null,
            'contact' => $validated['contact'] ?? null,
            'contacts' => $validated['contacts'] ?? null,
            'poll' => $validated['poll'] ?? null,
            'event' => $validated['event'] ?? null,
            'interactive' => $validated['interactive'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        $requestedKind = isset($validated['kind'])
            ? MessageKind::from((string) $validated['kind'])
            : null;
        if ($libraryStickerId !== null) {
            $requestedKind = MessageKind::Sticker;
        }

        return new MessageCreationData(
            body: trim((string) ($validated['body'] ?? '')),
            internalNote: (bool) ($validated['internal_note'] ?? false),
            requestedKind: $requestedKind,
            ptt: (bool) ($validated['ptt'] ?? false),
            gif: (bool) ($validated['gif'] ?? false),
            ptv: (bool) ($validated['ptv'] ?? false),
            viewOnce: (bool) ($validated['view_once'] ?? false),
            richPayload: $richPayload,
            replyToMessageId: isset($validated['reply_to_message_id'])
                ? (int) $validated['reply_to_message_id']
                : null,
            idempotencyKey: isset($validated['idempotency_key'])
                ? (string) $validated['idempotency_key']
                : null,
            upload: $this->uploadData($libraryStickerId),
            receiptMessageId: isset($validated['receipt_message_id'])
                ? (int) $validated['receipt_message_id']
                : null,
            libraryStickerId: $libraryStickerId,
            libraryStickerTempPath: $this->libraryStickerTempPath,
        );
    }

    private function uploadData(?string $libraryStickerId): ?MessageUploadData
    {
        if ($libraryStickerId !== null) {
            if (! (bool) config('communication.sticker_library.enabled', false)) {
                throw ValidationException::withMessages([
                    'library_sticker_id' => 'A biblioteca de figurinhas está desabilitada.',
                ]);
            }
            $conversation = $this->route('conversation');
            if (! $conversation instanceof CommunicationConversation) {
                throw ValidationException::withMessages([
                    'library_sticker_id' => 'Conversa inválida para envio da figurinha.',
                ]);
            }
            $resolved = app(LibraryStickerSendResolver::class)->resolve($conversation, $libraryStickerId);
            $this->libraryStickerTempPath = $resolved['temp_path'];

            return $resolved['upload'];
        }

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

        return new MessageUploadData(
            path: $path,
            originalName: $upload->getClientOriginalName(),
            detectedMime: (string) $upload->getMimeType(),
            clientMime: (string) $upload->getClientMimeType(),
        );
    }
}
