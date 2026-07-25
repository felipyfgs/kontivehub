import { expect, test, type Page } from '@playwright/test'

const allowedHosts = new Set(['127.0.0.1', 'localhost'])

async function login(page: Page): Promise<Page> {
  const request = page.context().request
  const csrfResponse = await request.get('/api/sanctum/sanctum/csrf-cookie')
  expect(csrfResponse.ok(), await csrfResponse.text()).toBe(true)
  const cookies = await page.context().cookies()
  const xsrfToken = cookies.find(cookie => cookie.name === 'XSRF-TOKEN')?.value
  expect(xsrfToken).toBeTruthy()

  const response = await request.post('/api/sanctum/login', {
    data: { email: 'operador@example.com', password: 'password' },
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': decodeURIComponent(xsrfToken || '')
    }
  })
  expect(response.ok(), await response.text()).toBe(true)
  return page
}

test.beforeEach(async ({ page }) => {
  await page.context().route('**/*', async (route) => {
    const url = new URL(route.request().url())
    if (!allowedHosts.has(url.hostname)) {
      await route.abort('blockedbyclient')
      return
    }
    await route.continue()
  })
})

test.setTimeout(600_000)

test('central cobre todas as declarações oficiais sem execução implícita', async ({ page: loginPage }, testInfo) => {
  const page = await login(loginPage)
  await page.setViewportSize({ width: 1366, height: 639 })

  const runtimeErrors: string[] = []
  const operationRequests: string[] = []
  page.on('pageerror', error => runtimeErrors.push(error.message))
  page.on('console', (message) => {
    if (message.type() === 'error' && !message.text().includes('ERR_BLOCKED_BY_CLIENT')) {
      runtimeErrors.push(message.text())
    }
  })
  page.on('request', (request) => {
    const pathname = new URL(request.url()).pathname
    if (pathname.includes('/api/v1/fiscal/declarations/operations/')) {
      operationRequests.push(`${request.method()} ${pathname}`)
    }
  })

  await page.goto('/monitoring/declarations')

  const tabs = page.getByTestId('declarations-submodule-tabs')
  // O primeiro carregamento do Nuxt dev compila o manifesto completo de rotas.
  await expect(tabs).toBeVisible({ timeout: 240_000 })
  for (const label of ['PGDAS-D', 'DEFIS', 'DASN-SIMEI', 'DCTFWeb', 'MIT', 'FGTS Digital', 'DIRF']) {
    await expect(tabs.getByRole('tab', { name: label })).toBeVisible()
  }
  await expect(tabs.getByRole('tab', { name: 'PGDAS-D' })).toHaveAttribute('aria-selected', 'true')
  await expect(page.getByTestId('fiscal-pagination')).toContainText('18 registro(s)')
  await page.screenshot({ path: testInfo.outputPath('declarations-desktop-1366x639.png'), fullPage: true })

  await page.getByTestId('declarations-operations-open').click()
  const modal = page.getByRole('dialog', { name: 'Central de operações · PGDAS' })
  await expect(modal).toBeVisible()
  await expect(modal.getByTestId('declaration-operation-list').locator('[data-testid^="declaration-operation-decl_"]')).toHaveCount(9)
  await expect(modal).toContainText('Nenhuma ação ocorre ao abrir este modal.')
  expect(operationRequests).toEqual([])
  await page.screenshot({ path: testInfo.outputPath('declarations-operations-desktop-1366x639.png'), fullPage: true })
  await modal.getByRole('button', { name: 'Fechar' }).last().click()

  const expectedCounts = [
    ['DEFIS', 'DEFIS', 4],
    ['DCTFWeb', 'DCTFWEB', 13],
    ['MIT', 'MIT', 4]
  ] as const
  for (const [label, obligation, count] of expectedCounts) {
    await tabs.getByRole('tab', { name: label }).click()
    await page.getByTestId('declarations-operations-open').click()
    const obligationModal = page.getByRole('dialog', { name: `Central de operações · ${obligation}` })
    await expect(obligationModal.getByTestId('declaration-operation-list').locator('[data-testid^="declaration-operation-decl_"]')).toHaveCount(count)
    await obligationModal.getByRole('button', { name: 'Fechar' }).last().click()
  }

  await tabs.getByRole('tab', { name: 'DASN-SIMEI' }).click()
  await page.getByTestId('declarations-operations-open').click()
  const dasnModal = page.getByRole('dialog', { name: 'Central de operações · DASN_SIMEI' })
  await expect(dasnModal.getByTestId('declaration-operation-list').locator('[data-testid^="declaration-operation-decl_"]')).toHaveCount(3)
  await expect(dasnModal).toContainText('Em prospecção')
  await expect(dasnModal.getByTestId('declaration-operation-submit')).toBeDisabled()
  expect(operationRequests).toEqual([])
  await dasnModal.getByRole('button', { name: 'Fechar' }).last().click()

  await tabs.getByRole('tab', { name: 'FGTS Digital' }).click()
  await expect(page.getByTestId('declarations-fgts-partial')).toBeVisible()
  await expect(page.getByTestId('declarations-operations-open')).toHaveCount(0)

  await tabs.getByRole('tab', { name: 'DIRF' }).click()
  await expect(page.getByTestId('declarations-dirf-unsupported')).toBeVisible()
  await expect(page.getByTestId('declarations-operations-open')).toHaveCount(0)

  expect(await page.evaluate(() => document.body.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1)

  await page.setViewportSize({ width: 390, height: 844 })
  await tabs.getByRole('tab', { name: 'PGDAS-D' }).click()
  await expect(page.getByTestId('declarations-operations-open')).toBeVisible()
  await expect(page.getByTestId('fiscal-pagination')).toContainText('18 registro(s)')
  await page.screenshot({ path: testInfo.outputPath('declarations-mobile-390x844.png'), fullPage: true })
  expect(await page.evaluate(() => document.body.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1)
  expect(operationRequests).toEqual([])
  expect(runtimeErrors).toEqual([])
})
