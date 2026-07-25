import { describe, expect, it } from 'vitest'
import type {
  DeclarationOperation,
  DeclarationOperationParam
} from '../../app/types/fiscal-modules'
import {
  buildDeclarationOperationParams,
  declarationMutationStatusMeta,
  declarationOperationAvailabilityMeta,
  declarationOperationDefaults
} from '../../app/utils/declaration-operations'

const periodField: DeclarationOperationParam = {
  name: 'period_key',
  type: 'month',
  required: true,
  label: 'Competência'
}

function operation(value: Partial<DeclarationOperation> = {}): DeclarationOperation {
  return {
    action_id: 'decl_pgdas_consultar_declaracoes',
    obligation: 'PGDAS',
    label: 'Consultar declarações',
    official_route: 'Consultar',
    flow: 'READ',
    official_state: 'PRODUCTION',
    implementation_state: 'IMPLEMENTED',
    availability: 'AVAILABLE',
    executable: true,
    requires_preflight: false,
    is_billable: true,
    async: true,
    params: [periodField],
    result_kind: 'STRUCTURED',
    ...value
  }
}

describe('declaration operations', () => {
  it('distingue consulta, mutação controlada e prospecção', () => {
    const operations = [
      operation(),
      operation({ action_id: 'decl_pgdas_entregar', flow: 'MUTATION', availability: 'CONTROLLED' }),
      operation({ action_id: 'decl_dasn_consultar', official_state: 'PROSPECTION', availability: 'PROSPECTION', executable: false })
    ]
    expect(operations.map(item => item.availability)).toEqual(['AVAILABLE', 'CONTROLLED', 'PROSPECTION'])
    expect(declarationOperationAvailabilityMeta('AVAILABLE').label).toBe('Disponível')
    expect(declarationOperationAvailabilityMeta('CONTROLLED').color).toBe('warning')
    expect(declarationOperationAvailabilityMeta('PROSPECTION').label).toBe('Em prospecção')
  })

  it('converte campos tipados e rejeita JSON ou obrigatórios inválidos', () => {
    const fields: DeclarationOperationParam[] = [
      periodField,
      { name: 'calendar_year', type: 'integer', required: true, label: 'Ano' },
      { name: 'business_payload', type: 'object', required: true, label: 'Declaração' },
      { name: 'source_system_ids', type: 'array', required: false, label: 'Origens' }
    ]
    expect(buildDeclarationOperationParams(fields, {
      period_key: '2026-07',
      calendar_year: '2026',
      business_payload: '{"declaracao":{"receita":100}}',
      source_system_ids: '[1,2]'
    })).toEqual({
      period_key: '2026-07',
      calendar_year: 2026,
      business_payload: { declaracao: { receita: 100 } },
      source_system_ids: [1, 2]
    })
    expect(() => buildDeclarationOperationParams(fields, {
      period_key: '',
      calendar_year: '2026',
      business_payload: '{}'
    })).toThrow('Informe: Competência')
    expect(() => buildDeclarationOperationParams([
      { name: 'payload', type: 'object', required: true, label: 'Payload' }
    ], { payload: '[]' })).toThrow('informe um objeto JSON')
  })

  it('inicializa editores JSON e mapeia estados sem sucesso prematuro', () => {
    expect(declarationOperationDefaults(operation({
      params: [
        { name: 'payload', type: 'object', required: true, label: 'Payload' },
        { name: 'items', type: 'array', required: false, label: 'Itens' }
      ]
    }))).toEqual({ payload: '{}', items: '[]' })
    expect(declarationMutationStatusMeta({ id: 1, client_id: 1, action_id: 'x', status: 'SENT' }).label).toBe('Processando')
    expect(declarationMutationStatusMeta({ id: 1, client_id: 1, action_id: 'x', status: 'UNKNOWN_RESULT' }).color).toBe('warning')
    expect(declarationMutationStatusMeta({ id: 1, client_id: 1, action_id: 'x', status: 'CONFIRMED' }).color).toBe('success')
  })
})
