import * as z from 'zod'
import type {
  RecurrenceFrequency,
  RecurrencePeriodOffset,
  WorkMonitoringModuleKey
} from '~/types/work'
import {
  RECURRENCE_MAX_GENERATION_DAY,
  RECURRENCE_MIN_GENERATION_DAY,
  isValidGenerationDay
} from '~/utils/work-routine-recurrence'

export const workAudienceRulesSchema = z.object({
  tax_regimes: z.array(z.string()),
  category_ids: z.array(z.number().int().positive()),
  category_match: z.enum(['ANY', 'ALL']),
  excluded_category_ids: z.array(z.number().int().positive())
})

export const workTemplateTaskSchema = z.object({
  title: z.string().trim().min(1, 'Informe o título da tarefa.'),
  due_rule_value: z.number().min(0, 'Use zero ou um valor positivo.').max(366, 'Use no máximo 366 dias.').nullable().optional(),
  default_department_id: z.number().int().positive().nullable().optional(),
  is_critical: z.boolean(),
  requires_evidence: z.boolean()
}).passthrough()

export const workTemplateFormSchema = z.object({
  name: z.string().trim().min(1, 'Informe o nome da rotina.'),
  description: z.string(),
  defaultDepartmentId: z.number().int().positive().nullable(),
  dueDay: z.number().min(0, 'Use um dia entre 0 e 31.').max(31, 'Use um dia entre 0 e 31.'),
  monitoringModuleKey: z.custom<WorkMonitoringModuleKey>().nullable(),
  audienceRules: workAudienceRulesSchema,
  isActive: z.boolean(),
  recurrenceEnabled: z.boolean(),
  recurrenceFrequency: z.custom<RecurrenceFrequency>().nullable(),
  generationDay: z.number(),
  periodOffset: z.custom<RecurrencePeriodOffset>(),
  nextRunAt: z.string().nullable(),
  tasks: z.array(workTemplateTaskSchema).min(1, 'Inclua ao menos uma tarefa.')
}).superRefine((value, context) => {
  if (!value.recurrenceEnabled) return
  if (!value.recurrenceFrequency) {
    context.addIssue({
      code: 'custom',
      path: ['recurrenceFrequency'],
      message: 'Selecione a frequência.'
    })
  }
  if (!isValidGenerationDay(value.generationDay)) {
    context.addIssue({
      code: 'custom',
      path: ['generationDay'],
      message: `Use um dia entre ${RECURRENCE_MIN_GENERATION_DAY} e ${RECURRENCE_MAX_GENERATION_DAY}.`
    })
  }
})

export type WorkTemplateFormSchema = z.output<typeof workTemplateFormSchema>

export const workGenerationFormSchema = z.object({
  competence: z.string().regex(/^\d{4}-\d{2}$/, 'Informe uma competência válida.'),
  rules: workAudienceRulesSchema,
  includeIds: z.array(z.number().int().positive()),
  excludeIds: z.array(z.number().int().positive())
})

export type WorkGenerationFormSchema = z.output<typeof workGenerationFormSchema>
