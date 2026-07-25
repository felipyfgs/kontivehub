import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * Superfícies migradas para cascas Shell* de modal.
 * Confirms reforçados de domínio (FiscalMutation / SerproOwner) mantêm UModal + ShellModalFooter.
 */
const FORM_OR_CONFIRM = [
  'app/components/data-table-filter/SaveFilterModal.vue',
  'app/components/settings/TeamAddModal.vue',
  'app/components/settings/DepartmentAddModal.vue',
  'app/components/clients/AssignCategoriesModal.vue',
  'app/components/clients/ClientFormModal.vue',
  'app/components/clients/ClientRegistrationRefreshModal.vue',
  'app/components/clients/ClientCredentialModal.vue',
  'app/components/fiscal/AssociateCategoriesModal.vue',
  'app/components/data-table-filter/ManageSavedFiltersModal.vue',
  'app/components/monitoring/RecentRefreshConfirmModal.vue',
  'app/components/settings/OfficeCredentialSection.vue',
  'app/pages/work/templates/index.vue',
  'app/pages/exports.vue',
  'app/pages/closing.vue',
  'app/components/monitoring/simples-mei/Portfolio.vue',
  'app/components/settings/OfficeProfileSection.vue',
  'app/components/communication/CommunicationWorkspacePage.vue',
  'app/components/communication/TimelinePanel.vue'
] as const

const SCROLLABLE = [
  'app/components/clients/ClientDetailModal.vue',
  'app/components/docs/DetailModal.vue',
  'app/components/clients/CategoryManagerModal.vue',
  'app/components/monitoring/DefisDeclarationsModal.vue',
  'app/components/monitoring/DefisLatestDeclarationModal.vue',
  'app/components/monitoring/DefisSpecificDeclarationModal.vue',
  'app/components/monitoring/DctfwebHistoryModal.vue',
  'app/components/monitoring/PgdasdDasHistoryModal.vue',
  'app/components/monitoring/PgmeiHistoryModal.vue',
  'app/components/monitoring/RegimeOptionModal.vue',
  'app/components/monitoring/RegimeResolutionModal.vue',
  'app/components/monitoring/RegimeCalendarModal.vue',
  'app/components/monitoring/MeiPublicServicesModal.vue'
] as const

/** Domínio reforçado: UModal permitido; footer deve usar ShellModalFooter. */
const REINFORCED = [
  'app/components/fiscal/FiscalMutationConfirmModal.vue',
  'app/components/serpro/SerproOwnerConfirmModal.vue'
] as const

const AD_HOC_FOOTER = /<div[^>]*class="[^"]*flex[^"]*justify-end[^"]*gap-2[^"]*"/

describe('shell-modals-migration-gate', () => {
  it('forms/confirms migrados usam ShellFormModal ou ShellConfirmModal (sem UModal raiz)', () => {
    for (const rel of FORM_OR_CONFIRM) {
      const source = readFileSync(resolve(process.cwd(), rel), 'utf8')
      const usesShell
        = source.includes('ShellFormModal')
          || source.includes('ShellConfirmModal')
      expect(usesShell, rel).toBe(true)
      expect(source, rel).not.toMatch(/<UModal[\s>]/)
    }
  })

  it('detalhes/históricos migrados usam ShellScrollableModal', () => {
    for (const rel of SCROLLABLE) {
      const source = readFileSync(resolve(process.cwd(), rel), 'utf8')
      expect(source, rel).toContain('ShellScrollableModal')
      expect(source, rel).not.toMatch(/<UModal[\s>]/)
    }
  })

  it('confirms reforçados usam ShellModalFooter (domínio)', () => {
    for (const rel of REINFORCED) {
      const source = readFileSync(resolve(process.cwd(), rel), 'utf8')
      expect(source, rel).toContain('ShellModalFooter')
      expect(source, rel).toMatch(/<UModal[\s>]/)
    }
  })

  it('slot #footer Cancel/Submit usa ShellModalFooter (detalhe complexo documentado fica de fora)', () => {
    /** Footers com meta + ações extras (não só Cancel/Submit). */
    const COMPLEX_FOOTER = new Set([
      'app/components/clients/ClientDetailModal.vue',
      'app/components/docs/DetailModal.vue'
    ])
    const modalFiles = [
      ...FORM_OR_CONFIRM.filter(p => p.endsWith('Modal.vue')),
      ...SCROLLABLE.filter(p => p.endsWith('Modal.vue')),
      ...REINFORCED
    ]
    const footerBlocks = /<template\s+#footer>([\s\S]*?)<\/template>/g
    for (const rel of modalFiles) {
      if (COMPLEX_FOOTER.has(rel)) continue
      const source = readFileSync(resolve(process.cwd(), rel), 'utf8')
      const blocks = [...source.matchAll(footerBlocks)].map(m => m[1] ?? '')
      if (!blocks.length) continue
      for (const block of blocks) {
        expect(block, `${rel} footer`).toContain('ShellModalFooter')
        if (rel.includes('FiscalMutation') || rel.includes('SerproOwner')) continue
        expect(block, `${rel} ad-hoc footer`).not.toMatch(AD_HOC_FOOTER)
      }
    }
  })
})
