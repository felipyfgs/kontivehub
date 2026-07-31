import { expect, test, type Page } from '@playwright/test'

const email = process.env.E2E_EMAIL || 'admin@kontivehub.local'
const password = process.env.E2E_PASSWORD || 'password'
const resetCredential = ['senha-segura', '123'].join('-')

async function expectBrowserLocation(
  page: Page,
  pathname: string,
  hash = ''
): Promise<void> {
  await expect.poll(() => {
    const current = new URL(page.url())
    return {
      pathname: current.pathname,
      search: current.search,
      hash: current.hash
    }
  }).toEqual({ pathname, search: '', hash })
}

async function login(page: Page): Promise<void> {
  await page.goto('/login')
  await page.getByLabel('E-mail').fill(email)
  await page.locator('input[name="password"]').fill(password)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page).not.toHaveURL(/\/login/)
}

async function completeLoginFromCurrentPage(page: Page): Promise<void> {
  await expect(page.getByLabel('E-mail')).toBeVisible()
  await page.getByLabel('E-mail').fill(email)
  await page.locator('input[name="password"]').fill(password)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page).not.toHaveURL(/\/login/)
}

test('bookmark legado aberto deslogado preserva a intenção após autenticar', async ({ page }) => {
  await page.goto('/communication?unassigned=1&desconhecida=segredo')
  await expectBrowserLocation(page, '/login')

  const filteredRequest = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname.endsWith('/api/v1/communication/conversations')
      && ['1', 'true'].includes(url.searchParams.get('unassigned') || '')
      && response.ok()
  })

  await completeLoginFromCurrentPage(page)
  await expectBrowserLocation(page, '/communication')
  await filteredRequest

  await expect(page.getByRole('tab', { name: 'Não atribuídas' }))
    .toHaveAttribute('aria-selected', 'true')
})

test('adaptador canonicaliza superfícies legadas sem remover query da API', async ({ page }) => {
  await login(page)

  const cases = [
    ['/health?type=cte_656&severity=high&desconhecida=x', '/health/type/cte_656'],
    ['/exports?new=1&page=2', '/exports/new'],
    ['/work/calendar?view=week&date=2026-07-30&department_id=1', '/work/calendar/week/2026-07-30'],
    ['/work/tasks?tab=atrasadas&client_id=1&desconhecida=x', '/work/tasks'],
    ['/docs/catalog?kind=cte&status=AUTHORIZED', '/docs/catalog/type/CTE']
  ] as const

  for (const [legacyPath, canonicalPath] of cases) {
    await page.goto(legacyPath)
    await expectBrowserLocation(page, canonicalPath)
  }
})

test('mensagem inválida volta à conversa sem desmontar o master-detail', async ({ page }) => {
  await login(page)
  await page.goto('/communication')

  const firstRow = page.locator('[data-testid^="communication-conversation-row-"]').first()
  await expect(firstRow).toBeVisible()
  const rowTestId = await firstRow.getAttribute('data-testid')
  const conversationId = rowTestId?.match(/(\d+)$/)?.[1]
  expect(conversationId).toBeTruthy()

  await page.goto(`/communication/conversations/${conversationId}/messages/invalida`)
  await expectBrowserLocation(page, `/communication/conversations/${conversationId}`)
  await expect(page.getByTestId('communication-list-panel')).toBeVisible()
  await expect(page.getByTestId('communication-timeline-panel')).toBeVisible()
})

test('reset legado consome token e e-mail e limpa query e fragmento', async ({ page }) => {
  await page.goto('/reset-password?token=abc%2B123&email=pessoa%2Bteste%40example.test')
  await expectBrowserLocation(page, '/reset-password')

  const resetRequest = page.waitForRequest((request) => {
    const url = new URL(request.url())
    return request.method() === 'POST' && url.pathname.endsWith('/reset-password')
  })
  await expect(page.getByTestId('reset-password-form')).toBeVisible()
  await page.getByLabel('Nova senha').fill(resetCredential)
  await page.getByLabel('Confirmar senha').fill(resetCredential)
  await page.getByRole('button', { name: 'Redefinir senha' }).click()

  expect((await resetRequest).postDataJSON()).toEqual({
    token: 'abc+123',
    email: 'pessoa+teste@example.test',
    password: resetCredential,
    password_confirmation: resetCredential
  })
  await expectBrowserLocation(page, '/reset-password')
})
