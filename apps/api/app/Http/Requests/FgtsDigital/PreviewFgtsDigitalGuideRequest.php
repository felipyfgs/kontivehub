<?php

namespace App\Http\Requests\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalPreviewData;
use App\Enums\FgtsDigitalGuideType;
use Illuminate\Validation\Rule;

final class PreviewFgtsDigitalGuideRequest extends OperateFgtsDigitalRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'guide_type' => [
                'required',
                'string',
                Rule::enum(FgtsDigitalGuideType::class),
            ],
            'parameters' => ['required', 'array'],
            'parameters.competence_period_key' => [
                'required',
                'regex:/^\d{4}-\d{2}$/',
            ],
            'parameters.amount_cents' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],
            'parameters.due_at' => ['sometimes', 'nullable', 'date'],
            'parameters.employee_ids' => ['sometimes', 'array', 'max:500'],
            'parameters.employee_ids.*' => ['string', 'max:80'],
            'parameters.debit_ids' => ['sometimes', 'array', 'max:500'],
            'parameters.debit_ids.*' => ['string', 'max:120'],
        ];
    }

    public function previewData(): FgtsDigitalPreviewData
    {
        $validated = $this->validated();

        return new FgtsDigitalPreviewData(
            clientId: (int) $validated['client_id'],
            guideType: FgtsDigitalGuideType::from(
                (string) $validated['guide_type'],
            ),
            parameters: $validated['parameters'],
        );
    }
}
