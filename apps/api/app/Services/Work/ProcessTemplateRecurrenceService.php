<?php

namespace App\Services\Work;

use App\Domain\Work\WorkRoutineRecurrenceSchedule;
use App\DTO\Work\ProcessTemplateRecurrenceData;
use App\Enums\Work\RecurrenceFrequency;
use App\Enums\Work\RecurrencePeriodOffset;
use App\Models\WorkProcessTemplate;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use App\Support\Work\OptimisticLock;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Configuração tenant-scoped da agenda de recorrência da Rotina.
 */
final class ProcessTemplateRecurrenceService
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly MembershipResolver $memberships,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPublic(WorkProcessTemplate $template): array
    {
        return [
            'recurrence_enabled' => (bool) $template->recurrence_enabled,
            'recurrence_frequency' => $template->recurrence_frequency?->value,
            'generation_day' => (int) ($template->generation_day ?? WorkRoutineRecurrenceSchedule::MIN_GENERATION_DAY),
            'anchor_month' => $template->anchor_month,
            'period_offset' => ($template->period_offset ?? RecurrencePeriodOffset::Previous)->value,
            'next_run_at' => $template->next_run_at?->toIso8601String(),
            'recurrence_owner_membership_id' => $template->recurrence_owner_membership_id,
        ];
    }

    /**
     * Indica se o payload traz campos de recorrência (create/update atômico).
     *
     * @param  array<string, mixed>  $input
     */
    public function hasPayload(array $input): bool
    {
        foreach ([
            'recurrence_enabled',
            'recurrence_frequency',
            'generation_day',
            'anchor_month',
            'period_offset',
            'recurrence_owner_membership_id',
        ] as $key) {
            if (array_key_exists($key, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Atributos de recorrência para persistência (create atômico ou update).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function attributesFromInput(array $input, ?WorkProcessTemplate $existing = null): array
    {
        $data = $this->validated($input);

        if (! empty($data['recurrence_owner_membership_id'])) {
            try {
                $this->memberships->requireActiveMembership((int) $data['recurrence_owner_membership_id']);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    'recurrence_owner_membership_id' => ['Membership inválida ou inativa neste escritório.'],
                ]);
            }
        }

        try {
            $schedule = WorkRoutineRecurrenceSchedule::fromArray([
                'recurrence_enabled' => $data['recurrence_enabled']
                    ?? $existing?->recurrence_enabled
                    ?? false,
                'recurrence_frequency' => $data['recurrence_frequency']
                    ?? $existing?->recurrence_frequency?->value,
                'generation_day' => $data['generation_day']
                    ?? $existing?->generation_day
                    ?? WorkRoutineRecurrenceSchedule::MIN_GENERATION_DAY,
                'anchor_month' => array_key_exists('anchor_month', $data)
                    ? $data['anchor_month']
                    : $existing?->anchor_month,
                'period_offset' => $data['period_offset']
                    ?? $existing?->period_offset?->value
                    ?? RecurrencePeriodOffset::Previous->value,
            ]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'recurrence' => [$e->getMessage()],
            ]);
        }

        $attrs = [
            'recurrence_enabled' => $schedule->enabled,
            'recurrence_frequency' => $schedule->frequency,
            'generation_day' => $schedule->generationDay,
            'anchor_month' => $schedule->enabled ? $schedule->anchorMonth : null,
            'period_offset' => $schedule->periodOffset,
            'recurrence_owner_membership_id' => array_key_exists('recurrence_owner_membership_id', $data)
                ? $data['recurrence_owner_membership_id']
                : ($existing?->recurrence_owner_membership_id),
        ];

        if ($schedule->enabled) {
            $attrs['next_run_at'] = $schedule->upcomingRunAtUtc($this->currentTenant->tenant());
        } else {
            $attrs['next_run_at'] = null;
            $attrs['recurrence_frequency'] = $schedule->frequency;
        }

        return $attrs;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(
        WorkProcessTemplate $template,
        ProcessTemplateRecurrenceData|array $input,
    ): WorkProcessTemplate {
        $input = $input instanceof ProcessTemplateRecurrenceData
            ? $input->attributes
            : $input;
        $tenantId = $this->currentTenant->id();
        if ($tenantId === null || (int) $template->tenant_id !== (int) $tenantId) {
            abort(404);
        }

        $attrs = $this->attributesFromInput($input, $template);

        $lockVersion = (int) ($input['lock_version'] ?? $template->lock_version);
        OptimisticLock::assert($template, $lockVersion, 'process_template');

        OptimisticLock::updateOrConflict($template, $lockVersion, $attrs, 'process_template');
        $template->refresh();

        $this->audit->record('work.template.recurrence.update', 'SUCCESS', $template, [
            'recurrence_enabled' => $template->recurrence_enabled,
            'recurrence_frequency' => $template->recurrence_frequency?->value,
            'generation_day' => $template->generation_day,
        ]);

        return $template;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validated(array $input): array
    {
        return Validator::make($input, $this->rules())->validate();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'recurrence_enabled' => ['sometimes', 'boolean'],
            'recurrence_frequency' => ['sometimes', 'nullable', 'string', Rule::enum(RecurrenceFrequency::class)],
            'generation_day' => [
                'sometimes',
                'integer',
                'min:'.WorkRoutineRecurrenceSchedule::MIN_GENERATION_DAY,
                'max:'.WorkRoutineRecurrenceSchedule::MAX_GENERATION_DAY,
            ],
            'anchor_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'period_offset' => ['sometimes', 'string', Rule::enum(RecurrencePeriodOffset::class)],
            'recurrence_owner_membership_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lock_version' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
