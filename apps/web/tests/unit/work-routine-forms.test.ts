import { describe, expect, it } from 'vitest'
import {
  workGenerationFormSchema,
  workTemplateFormSchema
} from '../../app/utils/work-routine-forms'

const validAudience = {
  tax_regimes: [],
  category_ids: [],
  category_match: 'ANY' as const,
  excluded_category_ids: []
}

function validTemplate() {
  return {
    name: 'PGDAS mensal',
    description: '',
    defaultDepartmentId: null,
    dueDay: 20,
    monitoringModuleKey: null,
    audienceRules: validAudience,
    isActive: true,
    recurrenceEnabled: false,
    recurrenceFrequency: null,
    generationDay: 1,
    periodOffset: 'PREVIOUS' as const,
    nextRunAt: null,
    tasks: [{
      title: 'Apurar receita',
      due_rule_value: 0,
      default_department_id: null,
      is_critical: false,
      requires_evidence: false
    }]
  }
}

describe('schemas dos formulários de Rotinas', () => {
  it('associa erros ao nome e à tarefa dinâmica', () => {
    const result = workTemplateFormSchema.safeParse({
      ...validTemplate(),
      name: '',
      tasks: [{ ...validTemplate().tasks[0], title: '' }]
    })

    expect(result.success).toBe(false)
    if (result.success) return
    expect(result.error.issues.map(issue => issue.path.join('.'))).toEqual(
      expect.arrayContaining(['name', 'tasks.0.title'])
    )
  })

  it('valida recorrência condicional por campo', () => {
    const result = workTemplateFormSchema.safeParse({
      ...validTemplate(),
      recurrenceEnabled: true,
      recurrenceFrequency: null,
      generationDay: 32
    })

    expect(result.success).toBe(false)
    if (result.success) return
    expect(result.error.issues.map(issue => issue.path.join('.'))).toEqual(
      expect.arrayContaining(['recurrenceFrequency', 'generationDay'])
    )
  })

  it('rejeita competência inválida na geração', () => {
    const result = workGenerationFormSchema.safeParse({
      competence: '07/2026',
      rules: validAudience,
      includeIds: [],
      excludeIds: []
    })

    expect(result.success).toBe(false)
    if (result.success) return
    expect(result.error.issues[0]?.path).toEqual(['competence'])
  })
})
