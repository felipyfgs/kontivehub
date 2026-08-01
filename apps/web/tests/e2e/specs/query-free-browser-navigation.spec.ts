import { expect, test, type Page } from '@playwright/test'

const email = process.env.E2E_EMAIL || 'admin@kontivehub.local'
const password = process.env.E2E_PASSWORD || 'password'

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
