import {
  COMMUNICATION_CONTACTS_PATH,
  communicationContactConversationsPath,
  communicationConversationMessagePath,
  parseCommunicationContactId,
  parseCommunicationConversationId
} from '~/utils/communication-routes'
import {
  isCommunicationContactSortField,
  isSensitiveCommunicationContactSearch
} from '~/utils/communication-contacts'
import { saveAuthReturn } from '~/utils/auth-return'
import {
  COMMUNICATION_SURFACES,
  SURFACE_NAVIGATION,
  WORK_SURFACES,
  publishSurfaceNavigationIntent
} from '~/composables/useSurfaceNavigationState'
import { healthTypePath } from '~/utils/health-navigation'
import { parseWorkProcessGroupingQuery } from '~/composables/useWorkProcessGrouping'
import { parseWorkQueueQuery } from '~/composables/useWorkQueueFilters'
import { workCalendarPath } from '~/composables/useWorkCalendarRange'
import { workProcessSectionPath } from '~/utils/work-navigation'
import {
  DOCUMENT_IMPORT_CREATE_PATH,
  documentCatalogClientPath,
  documentCatalogTypePath,
  normalizeDocumentContextKind
} from '~/utils/document-routes'
import { EXPORT_CREATE_PATH, EXPORTS_INDEX_PATH } from '~/utils/export-routes'
import {
  stageResetPasswordCredentials,
  stageResetPasswordCredentialsFromFragment
} from '~/utils/reset-password'

const CANONICALIZATION_HASH = '#legacy-canonical'

function scalar(value: unknown): string {
  const raw = Array.isArray(value) ? value[0] : value
  return raw === undefined || raw === null ? '' : String(raw)
}

function positiveInteger(value: unknown): number | null {
  const parsed = Number(scalar(value))
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
}

function hasAny(query: Record<string, unknown>, keys: readonly string[]): boolean {
  return keys.some(key => Object.hasOwn(query, key))
}

function canonicalProcessSection(path: string, rawSection: unknown): string {
  const processId = positiveInteger(path.split('/').at(-1))
  if (!processId) return '/work/processes'
  const section = scalar(rawSection).toLowerCase()
  if (section === 'tarefas' || section === 'tasks') return workProcessSectionPath(processId, 'tasks')
  if (section === 'comentarios' || section === 'comments') return workProcessSectionPath(processId, 'comments')
  if (section === 'historico' || section === 'history') return workProcessSectionPath(processId, 'history')
  return workProcessSectionPath(processId)
}

function legacyCalendarPath(query: Record<string, unknown>): string {
  const view = scalar(query.view).toLowerCase()
  const date = scalar(query.date)
  if (!['month', 'week', 'day'].includes(view) || !/^\d{4}-\d{2}-\d{2}$/.test(date)) {
    return '/work/calendar'
  }
  return workCalendarPath(view as 'month' | 'week' | 'day', date)
}

function canonicalizeBrowserLocation(currentPath: string, target: string) {
  if (target === currentPath) {
    return navigateTo(`${target}${CANONICALIZATION_HASH}`, { replace: true })
  }
  return navigateTo(target, { replace: true })
}

/**
 * Adaptador temporário e allowlisted para bookmarks anteriores ao path canônico.
 * O prefixo numérico garante execução antes de `auth.global.ts`.
 */
export default defineNuxtRouteMiddleware((to) => {
  if (to.hash === CANONICALIZATION_HASH && !Object.keys(to.query).length) {
    return navigateTo(to.path, { replace: true })
  }

  if (to.path === '/reset-password') {
    if (Object.keys(to.query).length) {
      const token = typeof to.query.token === 'string' ? to.query.token : ''
      const email = typeof to.query.email === 'string' ? to.query.email : ''
      stageResetPasswordCredentials(token, email)
      return canonicalizeBrowserLocation(to.path, '/reset-password')
    }
    if (to.hash) {
      stageResetPasswordCredentialsFromFragment(to.hash)
      return canonicalizeBrowserLocation(to.path, '/reset-password')
    }
    return
  }

  if (!Object.keys(to.query).length) return
  const query = to.query as Record<string, unknown>

  if (to.path === '/login' && typeof to.query.redirect === 'string') {
    saveAuthReturn(to.query.redirect)
    return canonicalizeBrowserLocation(to.path, '/login')
  }
  if (to.path === '/closing') {
    const keys = ['competence', 'band', 'model', 'root', 'source', 'client_id', 'page', 'per_page']
    if (hasAny(query, keys)) {
      publishSurfaceNavigationIntent(SURFACE_NAVIGATION.closing, {
        competence: scalar(query.competence),
        band: scalar(query.band),
        model: scalar(query.model),
        root: scalar(query.root),
        source: scalar(query.source),
        clientId: scalar(query.client_id),
        page: positiveInteger(query.page) ?? 1,
        perPage: positiveInteger(query.per_page) ?? 20
      })
    }
    return canonicalizeBrowserLocation(to.path, '/closing')
  }

  if (to.path === '/health' || to.path.startsWith('/health/type/')) {
    const severity = scalar(query.severity).toLowerCase()
    if (['critical', 'high', 'medium', 'low', 'all'].includes(severity)) {
      publishSurfaceNavigationIntent(SURFACE_NAVIGATION.health, { severity })
    }
    const type = scalar(query.type)
    const target = type ? healthTypePath(type) : to.path
    return canonicalizeBrowserLocation(to.path, target)
  }

  if (to.path === EXPORTS_INDEX_PATH || to.path === EXPORT_CREATE_PATH) {
    if (hasAny(query, ['page', 'per_page'])) {
      publishSurfaceNavigationIntent(SURFACE_NAVIGATION.exports, {
        page: positiveInteger(query.page) ?? 1,
        perPage: positiveInteger(query.per_page) ?? 20
      })
    }
    const create = scalar(query.new)
    const target = create === '1' || create === 'true' ? EXPORT_CREATE_PATH : to.path
    return canonicalizeBrowserLocation(to.path, target)
  }

  const workQueueKeys = [
    'tab', 'q', 'department_id', 'assignee_membership_id', 'client_id', 'scope',
    'page', 'per_page', 'view', 'sort', 'direction'
  ]
  if ((to.path === '/work/tasks' || /^\/work\/tasks\/\d+$/.test(to.path)) && hasAny(query, workQueueKeys)) {
    publishSurfaceNavigationIntent(WORK_SURFACES.queue, parseWorkQueueQuery(query))
    return canonicalizeBrowserLocation(to.path, to.path)
  }

  const workProcessKeys = [
    'group', 'q', 'competence', 'status', 'client_id', 'department_id',
    'page', 'per_page', 'sort', 'direction'
  ]
  if (to.path === '/work/processes' && hasAny(query, workProcessKeys)) {
    publishSurfaceNavigationIntent(WORK_SURFACES.processes, parseWorkProcessGroupingQuery(query))
    return canonicalizeBrowserLocation(to.path, '/work/processes')
  }

  if (/^\/work\/processes\/\d+$/.test(to.path) && hasAny(query, ['section', 'from'])) {
    return canonicalizeBrowserLocation(to.path, canonicalProcessSection(to.path, query.section))
  }

  if (to.path === '/work/calendar') {
    const filterKeys = ['department_id', 'assignee_membership_id', 'client_id', 'status', 'risk']
    if (hasAny(query, filterKeys)) {
      publishSurfaceNavigationIntent(WORK_SURFACES.calendar, {
        department_id: positiveInteger(query.department_id),
        assignee_membership_id: positiveInteger(query.assignee_membership_id),
        client_id: positiveInteger(query.client_id),
        status: scalar(query.status),
        risk: scalar(query.risk)
      })
    }
    return canonicalizeBrowserLocation(to.path, legacyCalendarPath(query))
  }

  if (to.path === '/work/templates') {
    const templateKeys = ['view', 'q', 'page', 'per_page', 'sort', 'direction']
    if (hasAny(query, templateKeys)) {
      const view = scalar(query.view) === 'tenant' ? 'tenant' : 'library'
      const sort = scalar(query.sort)
      publishSurfaceNavigationIntent(WORK_SURFACES.templates, {
        view,
        query: scalar(query.q),
        page: positiveInteger(query.page) ?? 1,
        perPage: [10, 20, 50].includes(positiveInteger(query.per_page) ?? 0)
          ? positiveInteger(query.per_page)
          : 20,
        tenantSort: ['name', 'is_active', 'id'].includes(sort) ? sort : null,
        tenantSortDirection: scalar(query.direction) === 'desc' ? 'desc' : 'asc'
      })
    }
    return canonicalizeBrowserLocation(to.path, '/work/templates')
  }

  if (to.path === '/clients') {
    const clientKeys = [
      'q', 'status', 'operational_filter', 'category_ids', 'tax_regimes',
      'procuracao_statuses', 'page', 'per_page', 'sort', 'sort_direction'
    ]
    if (hasAny(query, clientKeys)) {
      const status = scalar(query.status)
      const operationalFilter = scalar(query.operational_filter)
      const validOperationalFilter = [
        'total', 'with_credential', 'without_credential', 'expiring',
        'credential_expired', 'capture_problem'
      ].includes(operationalFilter)
      const sort = scalar(query.sort)
      publishSurfaceNavigationIntent(SURFACE_NAVIGATION.clients, {
        q: scalar(query.q),
        status: ['all', 'active', 'inactive'].includes(status) ? status : 'all',
        operational_filter: validOperationalFilter ? operationalFilter : 'total',
        category_ids: scalar(query.category_ids),
        tax_regimes: scalar(query.tax_regimes),
        procuracao_statuses: scalar(query.procuracao_statuses),
        page: positiveInteger(query.page) ?? 1,
        per_page: positiveInteger(query.per_page) ?? 20,
        sort: ['legal_name', 'is_active', 'tax_regime'].includes(sort) ? sort : 'legal_name',
        sort_direction: scalar(query.sort_direction) === 'desc' ? 'desc' : 'asc'
      })
    }
    return canonicalizeBrowserLocation(to.path, '/clients')
  }

  const docsCatalogKeys = [
    'kind', 'direction', 'client_id', 'establishment_id', 'fiscal_role',
    'acquisition_source', 'artifact_quality', 'coverage_status', 'status',
    'competence', 'issued_from', 'issued_to', 'missing_party_name', 'issuer_cnpj',
    'taker_cnpj', 'q'
  ]
  if (to.path === '/docs' || to.path === '/docs/catalog') {
    const kind = normalizeDocumentContextKind(query.kind)
    const clientId = positiveInteger(query.client_id)
    if (hasAny(query, docsCatalogKeys)) {
      publishSurfaceNavigationIntent(SURFACE_NAVIGATION.documents.catalog, {
        kind: kind ?? 'all',
        direction: scalar(query.direction) || 'all',
        client_id: clientId ? String(clientId) : 'all',
        establishment_id: scalar(query.establishment_id) || 'all',
        fiscal_role: scalar(query.fiscal_role) || 'all',
        acquisition_source: scalar(query.acquisition_source) || 'all',
        artifact_quality: scalar(query.artifact_quality) || 'all',
        coverage_status: scalar(query.coverage_status) || 'all',
        status: scalar(query.status) || 'all',
        competence: scalar(query.competence),
        issued_from: scalar(query.issued_from),
        issued_to: scalar(query.issued_to),
        missing_party_name: ['1', 'true'].includes(scalar(query.missing_party_name).toLowerCase()) ? '1' : '',
        issuer_cnpj: scalar(query.issuer_cnpj),
        taker_cnpj: scalar(query.taker_cnpj),
        q: scalar(query.q)
      })
    }
    if (to.path === '/docs' && hasAny(query, ['sort', 'sort_direction'])) {
      const sort = scalar(query.sort)
      publishSurfaceNavigationIntent(SURFACE_NAVIGATION.documents.byClient, {
        sort: sort === 'cnpj' ? 'cnpj' : 'legal_name',
        sort_direction: scalar(query.sort_direction) === 'desc' ? 'desc' : 'asc'
      })
    }
    const importRequested = ['1', 'true'].includes(scalar(query.import).toLowerCase())
    const target = importRequested
      ? DOCUMENT_IMPORT_CREATE_PATH
      : clientId
        ? documentCatalogClientPath(clientId)
        : kind
          ? documentCatalogTypePath(kind)
          : to.path
    return canonicalizeBrowserLocation(to.path, target)
  }

  if (to.path.startsWith(COMMUNICATION_CONTACTS_PATH)) {
    const contactKeys = [
      'page', 'per_page', 'q', 'is_active', 'is_provisional', 'linked',
      'sort', 'sort_direction'
    ]
    if (hasAny(query, contactKeys)) {
      const search = scalar(query.q)
      const triState = (value: unknown, fallback: 'all' | 'true') => {
        const normalized = scalar(value)
        return ['all', 'true', 'false'].includes(normalized) ? normalized : fallback
      }
      const sort = scalar(query.sort)
      publishSurfaceNavigationIntent(COMMUNICATION_SURFACES.contacts, {
        page: positiveInteger(query.page) ?? 1,
        per_page: [10, 20, 50].includes(positiveInteger(query.per_page) ?? 0)
          ? positiveInteger(query.per_page)
          : 20,
        q: isSensitiveCommunicationContactSearch(search) ? '' : search,
        is_active: triState(query.is_active, 'true'),
        is_provisional: triState(query.is_provisional, 'all'),
        linked: triState(query.linked, 'all'),
        sort: isCommunicationContactSortField(sort) ? sort : 'name',
        sort_direction: scalar(query.sort_direction) === 'desc' ? 'desc' : 'asc'
      })
    }
    return canonicalizeBrowserLocation(to.path, to.path)
  }

  if (to.path.startsWith('/communication/flows')) {
    const flowKeys = ['page', 'per_page', 'q', 'status']
    if (hasAny(query, flowKeys)) {
      const status = scalar(query.status)
      const perPage = positiveInteger(query.per_page)
      publishSurfaceNavigationIntent(COMMUNICATION_SURFACES.flows, {
        page: positiveInteger(query.page) ?? 1,
        per_page: perPage && [10, 20, 50].includes(perPage) ? perPage : 20,
        q: scalar(query.q),
        status: ['active', 'paused'].includes(status) ? status : 'all'
      })
    }
    return canonicalizeBrowserLocation(to.path, to.path)
  }

  if (to.path.startsWith('/communication/quick-responses')) {
    const quickResponseKeys = ['page', 'per_page', 'q', 'is_active']
    if (hasAny(query, quickResponseKeys)) {
      const active = scalar(query.is_active)
      const perPage = positiveInteger(query.per_page)
      publishSurfaceNavigationIntent(COMMUNICATION_SURFACES.quickResponses, {
        page: positiveInteger(query.page) ?? 1,
        per_page: perPage && [10, 20, 50].includes(perPage) ? perPage : 20,
        q: scalar(query.q),
        is_active: ['true', 'false'].includes(active) ? active : 'all'
      })
    }
    return canonicalizeBrowserLocation(to.path, to.path)
  }

  const contactId = parseCommunicationContactId(to.query.contact_id)
  if (to.path === '/communication' || to.path.startsWith('/communication/conversations/')) {
    const workspaceKeys = [
      'inbox_id', 'assignee_membership_id', 'work_department_id',
      'unassigned', 'unread', 'label_ids'
    ]
    const number = (value: unknown) => {
      const parsed = Number(value)
      return Number.isInteger(parsed) && parsed > 0 ? parsed : null
    }
    const labelIds = typeof to.query.label_ids === 'string'
      ? to.query.label_ids.split(',').map(Number).filter(id => Number.isInteger(id) && id > 0)
      : []
    if (hasAny(query, workspaceKeys)) {
      publishSurfaceNavigationIntent(COMMUNICATION_SURFACES.workspace, {
        inboxFilter: number(to.query.inbox_id),
        assigneeFilter: number(to.query.assignee_membership_id),
        departmentFilter: number(to.query.work_department_id),
        unassignedOnly: to.query.unassigned === '1' || to.query.unassigned === 'true',
        unreadOnly: to.query.unread === '1' || to.query.unread === 'true',
        labelIdsFilter: labelIds
      })
    }
  }
  const conversationId = parseCommunicationConversationId(to.params.id)
  const messageId = parseCommunicationConversationId(to.query.message_id)
  if (conversationId && messageId && to.path.startsWith('/communication/conversations/')) {
    return canonicalizeBrowserLocation(
      to.path,
      communicationConversationMessagePath(conversationId, messageId)
    )
  }
  if (to.path === '/communication' && contactId) {
    return canonicalizeBrowserLocation(to.path, communicationContactConversationsPath(contactId))
  }
  // Toda chave desconhecida é descartada silenciosamente.
  return canonicalizeBrowserLocation(to.path, to.path)
})
