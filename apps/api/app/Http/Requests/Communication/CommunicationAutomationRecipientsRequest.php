<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationAutomationScopeData;
use App\Models\Client;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\Automation\CommunicationAutomationCatalog;
use Illuminate\Validation\Validator;

abstract class CommunicationAutomationRecipientsRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $client = $this->route('client');

        return $actor instanceof User
            && $client instanceof Client
            && app(CommunicationAccess::class)->canManage($actor, $client);
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
                if (! app(CommunicationAutomationCatalog::class)->supports(
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

    public function scope(): CommunicationAutomationScopeData
    {
        $validated = $this->validated();

        return new CommunicationAutomationScopeData(
            moduleKey: $validated['module_key'],
            submoduleKey: $validated['submodule_key'],
        );
    }
}
