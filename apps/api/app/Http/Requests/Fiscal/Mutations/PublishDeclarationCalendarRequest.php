<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\PublishDeclarationCalendarData;
use App\Enums\TenantRole;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class PublishDeclarationCalendarRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && app(CurrentTenant::class)->role() === TenantRole::TenantAdmin;
    }

    protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)
            || $this->query->has('tenant_id')
            || $this->request->has('tenant_id')) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
                ],
            ]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:60'],
            'label' => ['required', 'string', 'max:160'],
            'source_ref' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'recalculate_open' => ['sometimes', 'boolean'],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.obligation_code' => ['required', 'string', 'max:60'],
            'rules.*.period_granularity' => ['nullable', 'string', 'max:20'],
            'rules.*.due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'rules.*.due_month_offset' => ['nullable', 'integer', 'min:0', 'max:24'],
            'rules.*.fixed_due_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'rules.*.fixed_due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'rules.*.business_day_adjustment' => ['nullable', 'string', 'max:20'],
            'rules.*.timezone' => ['nullable', 'string', 'max:64'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function calendarData(): PublishDeclarationCalendarData
    {
        $data = $this->validated();

        return new PublishDeclarationCalendarData(
            code: (string) ($data['code'] ?? 'RFB_NATIONAL'),
            label: (string) $data['label'],
            rules: is_array($data['rules'] ?? null) ? $data['rules'] : [],
            sourceRef: $data['source_ref'] ?? null,
            notes: $data['notes'] ?? null,
            timezone: (string) ($data['timezone'] ?? 'America/Sao_Paulo'),
            recalculateOpen: (bool) ($data['recalculate_open'] ?? true),
        );
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Somente ADMIN do escritório.');
    }
}
