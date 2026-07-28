<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\ExternalGateAcceptanceData;
use App\Enums\SerproEnvironment;
use App\Enums\SerproExternalGateKind;
use App\Exceptions\SerproConfigurationException;
use App\Http\Requests\AuthenticatedRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

final class AcceptSerproExternalGateRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'ticket_ref' => ['required', 'string', 'max:120'],
            'answer_summary' => ['required', 'string', 'max:1000'],
            'responsible_name' => ['required', 'string', 'max:200'],
            'reference_date' => ['required', 'date'],
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
        ];
    }

    public function toDto(string $gate): ExternalGateAcceptanceData
    {
        $kind = SerproExternalGateKind::tryFrom(strtoupper($gate));
        if ($kind === null) {
            throw SerproConfigurationException::unknownExternalGate();
        }

        $validated = $this->validated();

        return new ExternalGateAcceptanceData(
            kind: $kind,
            ticketReference: (string) $validated['ticket_ref'],
            answerSummary: (string) $validated['answer_summary'],
            responsibleName: (string) $validated['responsible_name'],
            referenceDate: CarbonImmutable::parse((string) $validated['reference_date']),
            environment: isset($validated['environment'])
                ? SerproEnvironment::from((string) $validated['environment'])
                : SerproEnvironment::Production,
        );
    }
}
