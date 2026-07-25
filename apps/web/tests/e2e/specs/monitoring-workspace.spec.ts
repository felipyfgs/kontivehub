import { expect, test, type Page } from '@playwright/test'

const allowedHosts = new Set(['127.0.0.1', 'localhost'])

async function login(page: Page, email: string) {
  await page.goto('/login')
  await page.getByLabel('E-mail').fill(email)
  await page.locator('input[name="password"]').fill('password')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page).not.toHaveURL(/\/login/)
}

async function selectOffice(page: Page, name: string) {
  const identity = page.getByTestId('office-identity')
  if (await identity.getAttribute('data-office-name') === name) return
  await identity.click()
  await page.locator('[role="option"]').filter({ hasText: name }).click()
  await expect(identity).toHaveAttribute('data-office-name', name)
}

test.beforeEach(async ({ page }) => {
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url())
    if (!allowedHosts.has(url.hostname)) {
      await route.abort('blockedbyclient')
      return
    }
    await route.continue()
  })
})

test('operador percorre superfícies e troca tenant sem egress fiscal', async ({ page }) => {
  await login(page, 'operador@example.com')
  await selectOffice(page, 'Escritório Contábil Demo')
  await page.goto('/monitoring')
  await expect(page.getByTestId('page-navbar')).toBeVisible()

  for (const route of [
    '/monitoring/simples',
    '/monitoring/dctfweb',
    '/monitoring/installments',
    '/monitoring/sitfis',
    '/monitoring/mailbox',
    '/monitoring/declarations',
    '/monitoring/guides',
    '/monitoring/registrations',
    '/monitoring/tax-processes',
    '/monitoring/fgts'
  ]) {
    await page.goto(route)
    await expect(page.locator('body')).not.toContainText('500 Internal Server Error')
  }

  await selectOffice(page, 'Escritório E2E Secundário')
  await selectOffice(page, 'Escritório Contábil Demo')
})

test('viewer vê histórico e cobertura sem controles de atualização', async ({ page }) => {
  await login(page, 'viewer@example.com')
  await page.goto('/monitoring')
  await expect(page.getByTestId('page-navbar')).toBeVisible()
  await expect(page.getByTestId('action-enqueue-read')).toHaveCount(0)
  await expect(page.getByTestId('manual-consult-run')).toHaveCount(0)

  await page.goto('/monitoring/fgts')
  await expect(page.locator('body')).toContainText(/FGTS|eSocial/i)
})
