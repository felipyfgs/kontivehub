<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationAutomationPolicyData;
use App\Enums\Communication\RecipientMode;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\Automation\CommunicationAutomationCatalog;
use App\Support\CurrentTenant;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpsertCommunicationAutomationPolicyRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(CommunicationAccess::class)->canManage($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'module_key' => ['required', 'string', 'max:40'],
            'submodule_key' => ['required', 'string', 'max:40'],
            'inbox_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('communication_inboxes', 'id')
                    ->where(fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'is_enabled' => ['required', 'boolean'],
            'send_day' => ['required', 'integer', 'between:1,28'],
            'send_time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'string', 'max:64', Rule::in(DateTimeZone::listIdentifiers())],
            'recipient_mode' => ['required', Rule::enum(RecipientMode::class)],
            'template_key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9._-]+$/i'],
            'template_version' => ['required', 'string', 'max:40'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $catalog = app(CommunicationAutomationCatalog::class);
                if (! $catalog->supports(
                    (string) $this->input('module_key'),
                    (string) $this->input('submodule_key'),
                )) {
                    $validator->errors()->add('module_key', 'Módulo de automação não suportado.');
                }
                if ($this->boolean('is_enabled') && $this->input('inbox_id') === null) {
                    $validator->errors()->add('inbox_id', 'Política ativa exige uma inbox geral.');
                }
            },
        ];
    }

    public function policyData(): CommunicationAutomationPolicyData
    {
        $validated = $this->validated();

        return new CommunicationAutomationPolicyData(
            moduleKey: $validated['module_key'],
            submoduleKey: $validated['submodule_key'],
            inboxId: isset($validated['inbox_id']) ? (int) $validated['inbox_id'] : null,
            isEnabled: (bool) $validated['is_enabled'],
            sendDay: (int) $validated['send_day'],
            sendTime: $validated['send_time'],
            timezone: $validated['timezone'],
            recipientMode: RecipientMode::from($validated['recipient_mode']),
            templateKey: $validated['template_key'],
            templateVersion: $validated['template_version'],
            lockVersion: (int) $validated['lock_version'],
        );
    }
}
