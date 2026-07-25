<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TAG = 'pgdasd-banking-day-v1';

    public function up(): void
    {
        DB::transaction(function (): void {
            $current = DB::table('tax_deadline_calendar_versions')
                ->where('code', 'RFB_NATIONAL')
                ->where('is_current', true)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();
            if ($current === null) {
                return;
            }

            $pgdasdId = DB::table('tax_obligation_definitions')
                ->where('code', 'PGDAS_D')
                ->value('id');
            if ($pgdasdId === null) {
                return;
            }
            $alreadyAdjusted = DB::table('tax_deadline_rules')
                ->where('calendar_version_id', $current->id)
                ->where('obligation_definition_id', $pgdasdId)
                ->where('business_day_adjustment', 'NEXT_BUSINESS_DAY')
                ->exists();
            if ($alreadyAdjusted) {
                return;
            }

            $now = now();
            DB::table('tax_deadline_calendar_versions')
                ->where('id', $current->id)
                ->update(['is_current' => false, 'effective_to' => $now, 'updated_at' => $now]);

            $metadata = $this->decodeMetadata($current->metadata ?? null);
            $metadata['previous_version_id'] = $current->id;
            $metadata['migration_tag'] = self::TAG;
            $metadata['verification'] = 'UNVERIFIED';
            $metadata['reason'] = 'PGDASD_NEXT_BANKING_DAY';
            $newCalendarId = DB::table('tax_deadline_calendar_versions')->insertGetId([
                'code' => $current->code,
                'version' => ((int) DB::table('tax_deadline_calendar_versions')
                    ->where('code', $current->code)
                    ->max('version')) + 1,
                'label' => $current->label.' + ajuste bancário PGDAS-D',
                'timezone' => $current->timezone,
                'effective_from' => $now,
                'effective_to' => null,
                'is_current' => true,
                'source_ref' => $current->source_ref,
                'notes' => trim((string) $current->notes.' ['.self::TAG.']'),
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (DB::table('tax_deadline_rules')->where('calendar_version_id', $current->id)->get() as $rule) {
                DB::table('tax_deadline_rules')->insert([
                    'calendar_version_id' => $newCalendarId,
                    'obligation_definition_id' => $rule->obligation_definition_id,
                    'period_granularity' => $rule->period_granularity,
                    'due_day' => $rule->due_day,
                    'due_month_offset' => $rule->due_month_offset,
                    'fixed_due_month' => $rule->fixed_due_month,
                    'fixed_due_day' => $rule->fixed_due_day,
                    'business_day_adjustment' => (int) $rule->obligation_definition_id === (int) $pgdasdId
                        ? 'NEXT_BUSINESS_DAY'
                        : $rule->business_day_adjustment,
                    'timezone' => $rule->timezone,
                    'metadata' => $rule->metadata,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $created = DB::table('tax_deadline_calendar_versions')
                ->where('code', 'RFB_NATIONAL')
                ->where('notes', 'like', '%['.self::TAG.']%')
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();
            if ($created === null) {
                return;
            }
            $metadata = $this->decodeMetadata($created->metadata ?? null);
            $previousId = $metadata['previous_version_id'] ?? null;
            DB::table('tax_deadline_calendar_versions')->where('id', $created->id)->delete();
            if (is_numeric($previousId)) {
                DB::table('tax_deadline_calendar_versions')->where('id', (int) $previousId)->update([
                    'is_current' => true,
                    'effective_to' => null,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /** @return array<string, mixed> */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
};
