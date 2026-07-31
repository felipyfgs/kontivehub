<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\AutomationScopeData;
use App\Models\Client;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use App\Services\Communication\Automation\AutomationCatalog;
use Illuminate\Validation\Validator;

abstract class CommunicationAutomationRecipientsRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $client = $this->route('client');

        return $actor instanceof User
            && $client instanceof Client
            && app(Access::class)->canManage($actor, $client);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'module_key' => ['required', 'string', 'max:40'],
            'submodule_key' => ['required', 'string', 'max:40'],
            ...$this->recipientRules(),
        ];
    }

    /** @return array<string, list<mixed>> */
    protected function recipientRules(): array
    {
        return [];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! app(AutomationCatalog::class)->supports(
                    (string) $this->input('module_key'),
                    (string) $this->input('submodule_key'),
                )) {
                    $validator->errors()->add('module_key', 'Módulo de automação não suportado.');
                }
            },
            ...$this->recipientAfterValidation(),
        ];
    }

    /** @return list<callable(Validator): void> */
    protected function recipientAfterValidation(): array
    {
        return [];
    }

    public function scope(): AutomationScopeData
    {
        $validated = $this->validated();

        return new AutomationScopeData(
            moduleKey: $validated['module_key'],
            submoduleKey: $validated['submodule_key'],
        );
    }
}
