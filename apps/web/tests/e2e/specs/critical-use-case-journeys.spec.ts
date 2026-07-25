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

test('operador alterna tenant e o catálogo acompanha o CurrentOffice', async ({ page }) => {
  await login(page, 'operador@example.com')
  await selectOffice(page, 'Escritório Contábil Demo')
  await page.goto('/clients')
  await expect(page.getByText('Cliente E2E Primário', { exact: true })).toBeVisible()
  await expect(page.getByText('Cliente E2E Secundário', { exact: true })).toHaveCount(0)

  await selectOffice(page, 'Escritório E2E Secundário')
  await expect(page.getByText('Cliente E2E Secundário', { exact: true })).toBeVisible()
  await expect(page.getByText('Cliente E2E Primário', { exact: true })).toHaveCount(0)
  await selectOffice(page, 'Escritório Contábil Demo')
})

test('catálogo de clientes diferencia as permissões de operador e viewer', async ({ page }) => {
  await login(page, 'viewer@example.com')
  await selectOffice(page, 'Escritório Contábil Demo')
  await page.goto('/clients')

  await expect(page.getByTestId('page-navbar')).toBeVisible()
  await expect(page.getByText('Cliente E2E Primário', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Novo cliente' })).toHaveCount(0)
  await expect(page.getByTestId('clients-bulk-actions')).toHaveCount(0)
})

test('dashboard e fila de trabalho isolam o tenant e mantêm viewer sem ações mutáveis', async ({ page }) => {
  await login(page, 'viewer@example.com')
  await selectOffice(page, 'Escritório Contábil Demo')
  await page.goto('/work')

  await expect(page.getByTestId('work-strategic-dashboard')).toBeVisible()
  await expect(page.getByTestId('work-dashboard-kpis')).toBeVisible()
  await expect(page.getByTestId('work-dashboard-departments')).toBeVisible()
  expect(await page.evaluate(() => document.body.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1)

  await page.goto('/work/tasks')

  await expect(page.getByTestId('work-queue-panel')).toBeVisible()
  await page.getByTestId('work-queue-item').filter({ hasText: 'Tarefa E2E Primário' }).click()
  await expect(page.getByTestId('work-task-detail')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Iniciar' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Concluir' })).toHaveCount(0)
  await expect(page.getByLabel('Novo comentário')).toHaveCount(0)

  const secondaryQueueResponse = page.waitForResponse(response =>
    response.url().includes('/api/sanctum/api/v1/work/queue') && response.ok())
  await selectOffice(page, 'Escritório E2E Secundário')
  await page.goto('/work/tasks')
  const secondaryQueue = await (await secondaryQueueResponse).json() as { data: Array<{ title: string }> }
  expect(secondaryQueue.data.map(task => task.title)).toContain('Tarefa E2E Secundário')
  expect(secondaryQueue.data.map(task => task.title)).not.toContain('Tarefa E2E Primário')
  await expect(page.getByTestId('office-identity')).toHaveAttribute('data-office-name', 'Escritório E2E Secundário')
  await selectOffice(page, 'Escritório Contábil Demo')
})
