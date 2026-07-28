import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('admin shell migration gate', () => {
  it('keeps the SERPRO settings shell fail-closed', () => {
    const page = source('app/pages/admin/serpro.vue')

    expect(page.match(/<ShellSettingsShell\b/g)).toHaveLength(1)
    expect(page).toContain('id="admin-serpro"')
    expect(page).toContain('title="Integração SERPRO"')
    expect(page).toContain('test-id="admin-serpro-panel"')
    expect(page).toMatch(/<template\s+v-if="canAccessPlatformSerpro"\s+#toolbar\s*>/)
    expect(page).toContain('<SectionNavigation')
    expect(page).toContain(':items="SERPRO_NAV_ITEMS"')
    expect(page).toContain('aria-label="Navegação do console SERPRO"')
    expect(page).toContain('test-id="admin-serpro-section-navigation"')
    expect(page).toContain('v-if="!canAccessPlatformSerpro"')
    expect(page).toContain('data-testid="admin-serpro-denied"')
    expect(page).toContain('<NuxtPage v-else />')
  })

  it('keeps tenant detail chrome, lifecycle and retry contracts', () => {
    const page = source('app/pages/admin/tenants/[id].vue')

    expect(page).toContain('<ShellPagePanel')
    expect(page).toContain('id="admin-tenant-detail"')
    expect(page).toContain('test-id="admin-tenant-detail"')
    expect(page).toContain('body-class="lg:py-12"')
    expect(page).toContain('<ShellPageNavbar :title="pageTitle">')
    expect(page).toMatch(/<template #leading>[\s\S]*?<ShellNavbarBack[\s\S]*?to="\/admin\/tenants"[\s\S]*?label="Lista"[\s\S]*?test-id="admin-tenant-back"[\s\S]*?<\/template>/)
    expect(page).toMatch(/<template #right>[\s\S]*?data-testid="admin-tenant-lifecycle-badge"[\s\S]*?<\/template>/)
    expect(page).toMatch(/<ShellLoadError[\s\S]*?test-id="admin-tenant-error"[\s\S]*?:description="loadError"[\s\S]*?@retry="load"/)
    expect(page).toContain('data-testid="admin-tenant-loading"')
    expect(page).not.toContain('<UDashboardNavbar')
  })

  it('keeps tenant creation chrome and wizard contracts', () => {
    const page = source('app/pages/admin/tenants/new.vue')

    expect(page).toContain('<ShellPagePanel')
    expect(page).toContain('id="admin-tenants-new"')
    expect(page).toContain('test-id="admin-tenants-new"')
    expect(page).toContain('body-class="lg:py-12"')
    expect(page).toContain('<ShellPageNavbar title="Novo escritório">')
    expect(page).toMatch(/<template #leading>[\s\S]*?<ShellNavbarBack[\s\S]*?to="\/admin\/tenants"[\s\S]*?label="Escritórios"[\s\S]*?<\/template>/)
    expect(page).toContain('data-testid="admin-tenant-mobile-progress"')
    expect(page).toContain('data-testid="admin-tenant-stepper"')
    expect(page).toContain('data-testid="admin-tenant-wizard-error"')
    expect(page).toContain('data-testid="wizard-submit"')
    expect(page).toContain('idempotency_key: idempotencyKey.value')
    expect(page).toContain('await router.replace(`/admin/tenants/${id}`)')
  })

  it('keeps responsive back buttons and retry emission in the shared shells', () => {
    const navbarBack = source('app/components/shell/NavbarBack.vue')
    const loadError = source('app/components/shell/LoadError.vue')

    expect(navbarBack.match(/<UButton\b/g)).toHaveLength(2)
    expect(navbarBack).toContain('class="hidden sm:inline-flex"')
    expect(navbarBack).toContain('class="sm:hidden"')
    expect(navbarBack).toContain(':aria-label="ariaLabel || label"')
    expect(navbarBack).toContain(':data-testid="`${testId}-mobile`"')
    expect(loadError).toContain('retry: []')
    expect(loadError).toContain(`onClick: () => emit('retry')`)
  })
})
