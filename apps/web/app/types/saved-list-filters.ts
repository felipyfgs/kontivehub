import type { components as PublicApiComponents } from '~/types/generated/public-api'

/** Superfícies estáveis de presets (schema_version 1). */
export const SAVED_LIST_SURFACES = [
  'monitoring.simples_mei',
  'monitoring.dctfweb',
  'monitoring.installments',
  'monitoring.sitfis',
  'monitoring.declarations',
  'monitoring.fgts',
  'monitoring.guides',
  'monitoring.registrations',
  'monitoring.tax_processes',
  'monitoring.mailbox',
  'clients.index',
  'docs.catalog',
  'work.queue',
  'work.processes',
  'closing.list'
] as const

export type SavedListSurface = PublicApiComponents['schemas']['SavedListSurface']

export const SAVED_LIST_SCHEMA_VERSION = 1 as const

export type SavedFilterVisibility = PublicApiComponents['schemas']['SavedFilterVisibility']
export type MonitoringSavedFilterPayload = PublicApiComponents['schemas']['MonitoringSavedFilterPayload']
export type ClientsSavedFilterPayload = PublicApiComponents['schemas']['ClientsSavedFilterPayload']
export type DocsSavedFilterPayload = PublicApiComponents['schemas']['DocsSavedFilterPayload']
export type WorkQueueSavedFilterPayload = PublicApiComponents['schemas']['WorkQueueSavedFilterPayload']
export type WorkProcessesSavedFilterPayload = PublicApiComponents['schemas']['WorkProcessesSavedFilterPayload']
export type ClosingSavedFilterPayload = PublicApiComponents['schemas']['ClosingSavedFilterPayload']
export type SavedListFilterPayload = PublicApiComponents['schemas']['SavedListFilterPayload']
export type SavedListFilter = PublicApiComponents['schemas']['SavedListFilter']
export type CreateSavedListFilterBody = PublicApiComponents['schemas']['CreateSavedListFilterBody']
export type UpdateSavedListFilterBody = PublicApiComponents['schemas']['UpdateSavedListFilterBody']

/** Mapa moduleKey / nav → surface de monitoring. */
export const MONITORING_MODULE_SURFACES: Record<string, SavedListSurface> = {
  simples_mei: 'monitoring.simples_mei',
  dctfweb: 'monitoring.dctfweb',
  installments: 'monitoring.installments',
  sitfis: 'monitoring.sitfis',
  declarations: 'monitoring.declarations',
  fgts: 'monitoring.fgts',
  guides: 'monitoring.guides',
  registrations: 'monitoring.registrations',
  tax_processes: 'monitoring.tax_processes',
  mailbox: 'monitoring.mailbox'
}

export function isSavedListSurface(value: string): value is SavedListSurface {
  return (SAVED_LIST_SURFACES as readonly string[]).includes(value)
}

export function isMonitoringSurface(surface: string): boolean {
  return surface.startsWith('monitoring.')
}

export function resolveMonitoringSurface(
  moduleKey?: string | null
): SavedListSurface | null {
  if (!moduleKey) return null
  return MONITORING_MODULE_SURFACES[moduleKey] ?? null
}
