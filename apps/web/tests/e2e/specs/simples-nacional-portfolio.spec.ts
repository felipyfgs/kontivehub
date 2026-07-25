import { expect, test, type Page } from '@playwright/test'

const allowedHosts = new Set(['127.0.0.1', 'localhost'])

async function login(page: Page, email: string) {
  await page.goto('/login')
  await page.getByLabel('E-mail').fill(email)
  await page.locator('input[name="password"]').fill('password')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page).not.toHaveURL(/\/login/)
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

test('legado /monitoring/simples-mei redireciona para /monitoring/simples', async ({ page }) => {
  await login(page, 'operador@example.com')
  await page.goto('/monitoring/simples-mei')
  await expect(page).toHaveURL(/\/monitoring\/simples\/?$/)
  await expect(page.locator('body')).not.toContainText('500 Internal Server Error')
})

test('operador vê carteira Simples Nacional sem clientes MEI e sem erro 500', async ({ page }) => {
  await login(page, 'operador@example.com')
  await page.goto('/monitoring/simples')
  await expect(page.getByTestId('page-navbar')).toBeVisible()
  await expect(page.locator('body')).toContainText(/Simples Nacional/i)
  await expect(page.locator('body')).not.toContainText('500 Internal Server Error')

  // Associação é mutação de operador; viewer não deve ver o botão.
  const associate = page.getByTestId('simples-associate-clients')
  if (await associate.count()) {
    await expect(associate).toBeVisible()
  }
})

test('viewer acessa a carteira sem controles de enqueue/consulta', async ({ page }) => {
  await login(page, 'viewer@example.com')
  await page.goto('/monitoring/simples')
  await expect(page.getByTestId('page-navbar')).toBeVisible()
  await expect(page.getByTestId('action-enqueue-read')).toHaveCount(0)
  await expect(page.getByTestId('manual-consult-run')).toHaveCount(0)
  await expect(page.getByTestId('simples-associate-clients')).toHaveCount(0)
  await expect(page.locator('body')).not.toContainText('500 Internal Server Error')
})
