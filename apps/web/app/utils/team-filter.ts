import type { TenantMember, TenantRole } from '~/types/api'

/** Filtro de papel na tela de equipe (`Todos` = sem restrição). */
export type TeamRoleFilter = 'ALL' | TenantRole

/** Normaliza texto de busca (trim + minúsculas) sem construir RegExp da entrada. */
export function normalizeTeamSearch(value: string): string {
  return value.trim().toLowerCase()
}

/**
 * Filtra memberships localmente por papel e texto (nome/e-mail).
 * Texto e papel são combinados (AND). Não usa RegExp construída da entrada.
 */
export function filterTeamMembers(
  members: TenantMember[],
  query: string,
  role: TeamRoleFilter
): TenantMember[] {
  const term = normalizeTeamSearch(query)

  return members.filter((member) => {
    if (role !== 'ALL' && member.role !== role) {
      return false
    }
    if (!term) {
      return true
    }
    const name = (member.name || '').toLowerCase()
    const email = (member.email || '').toLowerCase()
    return name.includes(term) || email.includes(term)
  })
}
