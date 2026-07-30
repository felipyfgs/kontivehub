import { describe, expect, it } from 'vitest'
import {
  COMMUNICATION_DEFAULT_SORT_BY,
  COMMUNICATION_SORT_BY_OPTIONS,
  COMMUNICATION_SORT_BY_VALUES,
  isCommunicationConversationSortBy,
  normalizeCommunicationConversationSortBy
} from '../../app/utils/communication-conversation-sort'

describe('communication conversation sort allowlist', () => {
  it('expõe a mesma allowlist do enum Laravel', () => {
    expect([...COMMUNICATION_SORT_BY_VALUES]).toEqual([
      'last_activity_desc',
      'last_activity_asc',
      'created_desc',
      'created_asc',
      'unread_desc',
      'priority_desc',
      'priority_asc'
    ])
    expect(COMMUNICATION_SORT_BY_OPTIONS.map(item => item.value))
      .toEqual([...COMMUNICATION_SORT_BY_VALUES])
    expect(COMMUNICATION_DEFAULT_SORT_BY).toBe('last_activity_desc')
  })

  it('normaliza string, item de select e valores inválidos', () => {
    expect(isCommunicationConversationSortBy('unread_desc')).toBe(true)
    expect(isCommunicationConversationSortBy('nope')).toBe(false)
    expect(normalizeCommunicationConversationSortBy('priority_asc')).toBe('priority_asc')
    expect(normalizeCommunicationConversationSortBy({ value: 'created_desc' }))
      .toBe('created_desc')
    expect(normalizeCommunicationConversationSortBy({ value: 'invalid' }))
      .toBe(COMMUNICATION_DEFAULT_SORT_BY)
    expect(normalizeCommunicationConversationSortBy(null))
      .toBe(COMMUNICATION_DEFAULT_SORT_BY)
    expect(normalizeCommunicationConversationSortBy(undefined))
      .toBe(COMMUNICATION_DEFAULT_SORT_BY)
  })
})
