import { describe, expect, it, vi } from 'vitest'
import type { DataTableFilterDefinition } from '../../app/types/data-table-filter'
import { createWorkAssigneeFilterModel } from '../../app/utils/work-queue-filter-models'

const definition: DataTableFilterDefinition = {
  key: 'assignee_membership_id',
  kind: 'option',
  label: 'Responsável',
  emptyValue: '',
  items: []
}

describe('work queue assignee filter model', () => {
  it('preserva chip de URL/preset quando o factory ainda não materializa o modelo', () => {
    const factory = vi.fn(() => null)
    expect(createWorkAssigneeFilterModel(
      definition,
      42,
      'Responsável #42',
      factory
    )).toEqual({
      key: 'assignee_membership_id',
      operator: 'eq',
      value: '42',
      label: 'Responsável #42'
    })
    expect(factory).toHaveBeenCalledOnce()
  })
})
