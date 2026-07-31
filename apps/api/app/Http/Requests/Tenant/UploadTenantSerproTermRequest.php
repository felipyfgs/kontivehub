<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\SerproTermUploadData;
use App\Enums\SerproEnvironment;
use Illuminate\Validation\Rule;

final class UploadTenantSerproTermRequest extends TenantSerproAuthorizationMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'termo_xml' => ['required_without:termo_file', 'string'],
            'termo_file' => ['required_without:termo_xml', 'file', 'max:2048'],
        ];
    }

    public function toDto(): SerproTermUploadData
    {
        return new SerproTermUploadData(
            environment: $this->environment(),
            xml: $this->validated('termo_xml'),
            filePath: $this->file('termo_file')?->getRealPath(),
            actorUserId: $this->actor()->id,
        );
    }
}
