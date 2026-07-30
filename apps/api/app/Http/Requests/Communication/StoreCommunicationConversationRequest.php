<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationMessageCreationData;
use App\DTO\Communication\CommunicationMessageUploadData;
use App\Enums\Communication\MessageKind;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StoreCommunicationConversationRequest extends CommunicationRequest
{
    protected function prepareCommunicationValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        if ($this->has('ptt')) {
            $this->merge(['ptt' => $this->boolean('ptt')]);
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        $inboxId = $this->input('inbox_id');
        if (! $actor instanceof User || ! is_numeric($inboxId)) {
            return false;
        }
        $inbox = CommunicationInbox::query()->find((int) $inboxId);

        return $inbox !== null && app(CommunicationAccess::class)->canReply($actor, $inbox);
    }

    /** @return array<string,list<mixed>> */
    public function rules(): array
    {
        return ['contact_id' => ['required', 'integer', 'min:1'], 'identity_id' => ['required', 'integer', 'min:1'], 'inbox_id' => ['required', 'integer', 'min:1'], 'body' => ['nullable', 'string', 'required_without:file', static function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && strlen($value) > 4096) {
                $fail('O campo '.$attribute.' não pode ter mais que 4096 bytes.');
            }
        }], 'file' => ['nullable', 'file', 'max:'.max(1, (int) ceil(((int) config('communication.media.max_bytes', 20971520)) / 1024)), 'mimetypes:image/jpeg,image/png,image/webp,audio/ogg,audio/mpeg,audio/mp4,audio/webm,video/mp4,video/webm,application/pdf,text/plain,application/zip'], 'kind' => ['nullable', Rule::in(['TEXT', 'IMAGE', 'AUDIO', 'VIDEO', 'DOCUMENT', 'STICKER'])], 'ptt' => ['sometimes', 'boolean'], 'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/']];
    }

    public function contactId(): int
    {
        return (int) $this->validated()['contact_id'];
    }

    public function identityId(): int
    {
        return (int) $this->validated()['identity_id'];
    }

    public function inboxId(): int
    {
        return (int) $this->validated()['inbox_id'];
    }

    public function messageData(): CommunicationMessageCreationData
    {
        $v = $this->validated();
        $upload = $this->file('file');
        $uploadData = null;
        if ($upload instanceof UploadedFile) {
            $path = $upload->getRealPath();
            if (! is_string($path) || $path === '') {
                throw ValidationException::withMessages(['file' => 'Arquivo inválido.']);
            } $uploadData = new CommunicationMessageUploadData($path, $upload->getClientOriginalName(), (string) $upload->getMimeType(), (string) $upload->getClientMimeType());
        }

        return new CommunicationMessageCreationData(trim((string) ($v['body'] ?? '')), false, isset($v['kind']) ? MessageKind::from($v['kind']) : null, (bool) ($v['ptt'] ?? false), false, [], null, (string) $v['idempotency_key'], $uploadData, null, true);
    }
}
