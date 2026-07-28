<?php

namespace App\Http\Requests\Imports;

use App\DTO\Import\DocumentImportBatchAdmissionData;
use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class StoreDocumentImportBatchRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows(
                $actor,
                TenantPermission::DocumentsImport,
            );
    }

    protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(
            EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED,
        ) || $this->request->has('tenant_id') || $this->query->has('tenant_id')) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
                ],
            ]);
        }

        if ($this->filled('idempotency_key')) {
            return;
        }

        $header = $this->header('Idempotency-Key');
        if (is_string($header) && trim($header) !== '') {
            $this->merge(['idempotency_key' => $header]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'files' => [
                'required',
                'array',
                'min:1',
                'max:'.(int) config('import.max_top_level_files', 50),
            ],
            'files.*' => [
                'file',
                'max:'.(int) config('import.max_file_kib', 20480),
            ],
            'client_id' => ['nullable', 'integer'],
            'establishment_id' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function admissionData(): DocumentImportBatchAdmissionData
    {
        return new DocumentImportBatchAdmissionData(
            actor: $this->actor(),
            files: $this->files(),
            clientId: $this->clientId(),
            establishmentId: $this->establishmentId(),
            idempotencyKey: $this->idempotencyKey(),
        );
    }

    /** @return list<UploadedFile> */
    public function files(): array
    {
        $files = $this->file('files', []);
        if (! is_array($files)) {
            $files = [$files];
        }

        return array_values(array_filter(
            $files,
            static fn ($file): bool => $file instanceof UploadedFile,
        ));
    }

    public function clientId(): ?int
    {
        $value = $this->validated('client_id');

        return $value !== null ? (int) $value : null;
    }

    public function establishmentId(): ?int
    {
        $value = $this->validated('establishment_id');

        return $value !== null ? (int) $value : null;
    }

    public function idempotencyKey(): ?string
    {
        $value = $this->validated('idempotency_key');

        return is_string($value) ? $value : null;
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Ação não autorizada para o perfil atual.');
    }
}
