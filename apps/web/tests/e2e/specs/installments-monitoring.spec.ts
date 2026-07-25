import { expect, test, type Page } from '@playwright/test'

async function login(page: Page) {
  await page.goto('/login')
  await page.getByLabel('E-mail').fill('operador@example.com')
  await page.locator('input[name="password"]').fill('password')
  const loginResponse = page.waitForResponse(response => response.url().endsWith('/api/sanctum/login'))
  await page.getByRole('button', { name: 'Entrar' }).click()
  const response = await loginResponse
  expect(response.ok(), await response.text()).toBe(true)
  await expect(page).toHaveURL(/\/work(?:\?.*)?$/)
}

test('carteira mantém tabs por modalidade, fail-closed e layout responsivo', async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 1366, height: 639 })
  await login(page)

  const runtimeErrors: string[] = []
  page.on('pageerror', error => runtimeErrors.push(error.message))
  page.on('console', (message) => {
    if (message.type() === 'error') runtimeErrors.push(message.text())
  })

  await page.goto('/monitoring/installments')

  const tabs = page.getByTestId('installments-type-tabs')
  await expect(tabs).toBeVisible()
  await expect(tabs.getByRole('tab')).toHaveCount(11)
  await expect(tabs.getByRole('tab', { name: 'Todos' })).toHaveAttribute('aria-selected', 'true')
  for (const label of [
    'Simples', 'Simples Especial', 'PERT Simples', 'RELP Simples',
    'MEI', 'MEI Especial', 'PERT MEI', 'RELP MEI'
  ]) {
    await expect(tabs.getByRole('tab', { name: label, exact: true })).toBeEnabled()
  }
  await expect(tabs.getByRole('tab', { name: 'PAEX · em prospecção' })).toBeDisabled()
  await expect(tabs.getByRole('tab', { name: 'SIPADE · em prospecção' })).toBeDisabled()
  await expect(page.getByRole('button', { name: 'Consultar todos' })).toBeVisible()

  const simplesResponse = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return url.pathname.endsWith('/api/v1/fiscal/modules/installments/clients')
      && url.searchParams.get('modality') === 'PARCSN'
  })
  await tabs.getByRole('tab', { name: 'Simples', exact: true }).click()
  await simplesResponse
  await expect(tabs.getByRole('tab', { name: 'Simples', exact: true })).toHaveAttribute('aria-selected', 'true')
  await page.screenshot({ path: testInfo.outputPath('installments-desktop.png'), fullPage: true })

  const desktopOverflow = await page.evaluate(() => document.body.scrollWidth - window.innerWidth)
  expect(desktopOverflow).toBeLessThanOrEqual(1)

  await page.setViewportSize({ width: 390, height: 844 })
  await expect(tabs).toBeVisible()
  await page.screenshot({ path: testInfo.outputPath('installments-mobile.png'), fullPage: true })

  const mobileOverflow = await page.evaluate(() => document.body.scrollWidth - window.innerWidth)
  expect(mobileOverflow).toBeLessThanOrEqual(1)
  expect(runtimeErrors).toEqual([])
})
