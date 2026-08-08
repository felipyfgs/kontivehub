<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\MessageBatchCreationData;
use App\DTO\Communication\MessageCreationData;
use App\DTO\Communication\MessageUploadData;
use App\Enums\Communication\MessageKind;
use App\Models\CommunicationConversation;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StoreMessageBatchRequest extends TenantScopedRequest
{
    protected function prepareScopedValidation(): void
    {
        $items = $this->input('items');
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['gif', 'ptv', 'view_once'] as $field) {
                if (array_key_exists($field, $item)) {
                    $items[$index][$field] = filter_var($item[$field], FILTER_VALIDATE_BOOL);
                }
            }
        }
        $this->merge(['items' => $items]);
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        $conversation = $this->route('conversation');
        if (! $actor instanceof User || ! $conversation instanceof CommunicationConversation) {
            return false;
        }
        $inbox = $conversation->inbox()->first();

        return $inbox !== null && app(Access::class)->canReply($actor, $inbox);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $maxKilobytes = max(1, (int) ceil(((int) config('communication.media.max_bytes', 20_971_520)) / 1024));

        return [
            'client_batch_id' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/'],
            'items' => ['required', 'array', 'min:2', 'max:10'],
            'items.*' => ['required', 'array:file,kind,caption,gif,ptv,view_once'],
            'items.*.file' => [
                'required',
                'file',
                'max:'.$maxKilobytes,
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm,application/pdf,text/plain,application/zip',
            ],
            'items.*.kind' => ['required', Rule::in(['IMAGE', 'VIDEO', 'DOCUMENT'])],
            'items.*.caption' => ['nullable', 'string', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 4096) {
                    $fail('O campo '.$attribute.' não pode ter mais que 4096 bytes.');
                }
            }],
            'items.*.gif' => ['sometimes', 'boolean'],
            'items.*.ptv' => ['sometimes', 'boolean'],
            'items.*.view_once' => ['sometimes', 'boolean'],
        ];
    }

    public function batchData(): MessageBatchCreationData
    {
        $validated = $this->validated();
        $clientBatchId = (string) $validated['client_batch_id'];
        $conversation = $this->route('conversation');
        if (! $conversation instanceof CommunicationConversation) {
            throw ValidationException::withMessages(['client_batch_id' => 'Conversa inválida.']);
        }
        $items = [];
        $digestItems = [];
        foreach ($validated['items'] as $position => $item) {
            $upload = $this->uploadData((int) $position, $item['file']);
            $fileDigest = hash_file('sha256', $upload->path);
            if (! is_string($fileDigest)) {
                throw ValidationException::withMessages(['items.'.$position.'.file' => 'Arquivo inválido.']);
            }
            $kind = MessageKind::from((string) $item['kind']);
            $idempotencyKey = 'batch-'.substr(hash('sha256', $conversation->id.'|'.$clientBatchId.'|'.$position), 0, 48);
            $items[] = new MessageCreationData(
                body: trim((string) ($item['caption'] ?? '')),
                internalNote: false,
                requestedKind: $kind,
                ptt: false,
                gif: (bool) ($item['gif'] ?? false),
                richPayload: [],
                replyToMessageId: null,
                idempotencyKey: $idempotencyKey,
                upload: $upload,
                ptv: (bool) ($item['ptv'] ?? false),
                viewOnce: (bool) ($item['view_once'] ?? false),
            );
            $digestItems[] = [
                'position' => (int) $position,
                'kind' => $kind->value,
                'caption' => trim((string) ($item['caption'] ?? '')),
                'gif' => (bool) ($item['gif'] ?? false),
                'ptv' => (bool) ($item['ptv'] ?? false),
                'view_once' => (bool) ($item['view_once'] ?? false),
                'sha256' => $fileDigest,
            ];
        }

        return new MessageBatchCreationData(
            clientBatchId: $clientBatchId,
            requestDigest: hash('sha256', json_encode($digestItems, JSON_THROW_ON_ERROR)),
            items: $items,
        );
    }

    private function uploadData(int $position, mixed $file): MessageUploadData
    {
        if (! $file instanceof UploadedFile || ! is_string($file->getRealPath())) {
            throw ValidationException::withMessages(['items.'.$position.'.file' => 'Arquivo inválido.']);
        }

        return new MessageUploadData(
            path: $file->getRealPath(),
            originalName: $file->getClientOriginalName(),
            detectedMime: (string) $file->getMimeType(),
            clientMime: (string) $file->getClientMimeType(),
        );
    }
}
