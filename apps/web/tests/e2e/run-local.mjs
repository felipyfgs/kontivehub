import { spawn } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import process from 'node:process'

const webRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const repoRoot = resolve(webRoot, '../..')
const project = process.env.E2E_COMPOSE_PROJECT || 'fiscal-monitor-e2e'
const composeArgs = ['compose', '-p', project, '-f', 'docker-compose.yml']
const playwrightArgs = process.argv.slice(2).filter(argument => argument !== '--')
const e2eWebPort = process.env.E2E_WEB_PORT || '13000'
const env = {
  ...process.env,
  COMPOSE_PROJECT_NAME: project,
  LOCAL_UID: String(process.getuid?.() || 1000),
  LOCAL_GID: String(process.getgid?.() || 1000),
  PNPM_CONFIG_STORE_DIR: '/tmp/frontend-home/.local/share/pnpm/store',
  APP_ENV: 'testing',
  DB_DATABASE: 'nfse_e2e',
  E2E_API_PORT: process.env.E2E_API_PORT || '18080',
  E2E_WEB_PORT: e2eWebPort,
  E2E_BASE_URL: process.env.E2E_BASE_URL || `http://127.0.0.1:${e2eWebPort}`,
  E2E_POSTGRES_PORT: process.env.E2E_POSTGRES_PORT || '15432',
  E2E_REDIS_PORT: process.env.E2E_REDIS_PORT || '16379',
  POSTGRES_PORT: process.env.E2E_POSTGRES_PORT || '15432',
  REDIS_PORT: process.env.E2E_REDIS_PORT || '16379',
  APP_PORT: process.env.E2E_API_PORT || '18080',
  FRONTEND_DEV_PORT: e2eWebPort,
  NUXT_DEVTOOLS: 'false',
  NUXT_PWA_DEV: 'false',
  SANCTUM_STATEFUL_DOMAINS: process.env.SANCTUM_STATEFUL_DOMAINS || `127.0.0.1:${e2eWebPort},localhost:${e2eWebPort},127.0.0.1,localhost`,
  FISCAL_DEMO_TENANT_SLUG: 'contador',
  FISCAL_DEMO_SENTINEL_SLUG: 'plataforma',
  WORK_DEMO_TENANT_SLUG: 'contador',
  WORK_DEMO_SENTINEL_SLUG: 'plataforma',
  FISCAL_PROFILE: process.env.E2E_FISCAL_PROFILE || 'dev',
  FISCAL_MONITORING_MUTATING_ENABLED: process.env.E2E_FISCAL_MONITORING_MUTATING_ENABLED || 'false',
  FISCAL_KILL_SWITCH: process.env.E2E_FISCAL_KILL_SWITCH || 'true'
}

function run(command, args, options = {}) {
  return new Promise((resolvePromise, reject) => {
    const child = spawn(command, args, {
      cwd: options.cwd || repoRoot,
      env,
      stdio: 'inherit'
    })
    child.on('error', reject)
    child.on('exit', code => code === 0
      ? resolvePromise()
      : reject(new Error(`${command} terminou com status ${code}`)))
  })
}

async function waitFor(url, timeoutMs = 120_000) {
  const deadline = Date.now() + timeoutMs
  while (Date.now() < deadline) {
    try {
      const response = await fetch(url, { redirect: 'manual' })
      if (response.status < 500) return
    } catch {
      // Serviço ainda inicializando.
    }
    await new Promise(resolvePromise => setTimeout(resolvePromise, 1_000))
  }
  throw new Error(`Timeout aguardando ${url}`)
}

try {
  await run('docker', [
    ...composeArgs,
    'run', '--rm', '--no-deps', 'frontend', 'prepare'
  ])
  await run('docker', [
    ...composeArgs,
    'up', '-d', '--build',
    'postgres', 'redis', 'php', 'nginx', 'frontend'
  ])
  await run('docker', [
    ...composeArgs,
    'exec',
    '-T',
    '--user', 'www-data',
    '-e', 'FISCAL_DEMO_TENANT_SLUG=contador',
    '-e', 'FISCAL_DEMO_SENTINEL_SLUG=plataforma',
    '-e', 'WORK_DEMO_TENANT_SLUG=contador',
    '-e', 'WORK_DEMO_SENTINEL_SLUG=plataforma',
    '-e', 'FISCAL_MONITORING_MUTATING_ENABLED=false',
    'php',
    'php', 'artisan', 'migrate:fresh', '--force', '--seed',
    '--seeder=Database\\Seeders\\Testing\\WebE2ESeeder'
  ])
  await waitFor(`http://127.0.0.1:${env.E2E_API_PORT}/up`)
  await waitFor(`http://127.0.0.1:${env.E2E_WEB_PORT}/login`)
  await run(resolve(webRoot, 'node_modules/.bin/playwright'), ['test', ...playwrightArgs], { cwd: webRoot })
} finally {
  if (process.env.E2E_KEEP_STACK !== 'true') {
    await run('docker', [
      ...composeArgs,
      'down', '--volumes', '--remove-orphans'
    ])
      .catch(error => console.error(`Falha na limpeza E2E: ${error.message}`))
  }
}
