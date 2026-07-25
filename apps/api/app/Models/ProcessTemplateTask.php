<?php

namespace App\Models;

use App\Enums\Work\DueRuleType;
use App\Models\Concerns\BelongsToOffice;
use Database\Factories\ProcessTemplateTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'process_template_id',
    'sort_order',
    'title',
    'description',
    'due_rule_type',
    'due_rule_value',
    'default_department_id',
    'default_assignee_membership_id',
    'is_required',
    'is_critical',
    'requires_evidence',
])]
class ProcessTemplateTask extends Model
{
    /** @use HasFactory<ProcessTemplateTaskFactory> */
    use BelongsToOffice, HasFactory;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'due_rule_type' => DueRuleType::class,
            'due_rule_value' => 'integer',
            'is_required' => 'boolean',
            'is_critical' => 'boolean',
            'requires_evidence' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProcessTemplate::class, 'process_template_id');
    }

    public function defaultDepartment(): BelongsTo
    {
        return $this->belongsTo(WorkDepartment::class, 'default_department_id');
    }

    public function defaultAssigneeMembership(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'default_assignee_membership_id');
    }

    protected static function newFactory(): ProcessTemplateTaskFactory
    {
        return ProcessTemplateTaskFactory::new();
    }
}
