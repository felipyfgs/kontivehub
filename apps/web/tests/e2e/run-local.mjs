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
  SANCTUM_STATEFUL_DOMAINS: process.env.SANCTUM_STATEFUL_DOMAINS || `127.0.0.1:${e2eWebPort},localhost:${e2eWebPort},127.0.0.1,localhost`,
  FISCAL_DEMO_OFFICE_SLUG: 'contador',
  FISCAL_DEMO_SENTINEL_SLUG: 'plataforma',
  WORK_DEMO_OFFICE_SLUG: 'contador',
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

function start(command, args, options = {}) {
  return spawn(command, args, {
    cwd: options.cwd || repoRoot,
    env: { ...env, ...(options.env || {}) },
    stdio: 'inherit'
  })
}

async function stop(child) {
  if (!child || child.exitCode !== null) return
  child.kill('SIGTERM')
  await Promise.race([
    new Promise(resolvePromise => child.once('exit', resolvePromise)),
    new Promise(resolvePromise => setTimeout(resolvePromise, 5_000))
  ])
  if (child.exitCode === null) child.kill('SIGKILL')
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

let nuxtProcess
try {
  await run('docker', [...composeArgs, 'up', '-d', '--build', 'postgres', 'redis', 'php', 'nginx'])
  await run('docker', [
    ...composeArgs,
    'exec',
    '-T',
    '-e', 'FISCAL_DEMO_OFFICE_SLUG=contador',
    '-e', 'FISCAL_DEMO_SENTINEL_SLUG=plataforma',
    '-e', 'WORK_DEMO_OFFICE_SLUG=contador',
    '-e', 'WORK_DEMO_SENTINEL_SLUG=plataforma',
    '-e', 'FISCAL_MONITORING_MUTATING_ENABLED=false',
    'php',
    'php', 'artisan', 'migrate:fresh', '--force', '--seed', '--seeder=FiscalMonitoringE2ESeeder'
  ])
  await waitFor(`http://127.0.0.1:${env.E2E_API_PORT}/up`)
  nuxtProcess = start('corepack', ['pnpm', 'exec', 'nuxt', 'dev', '--host', '127.0.0.1', '--port', env.E2E_WEB_PORT], {
    cwd: webRoot,
    env: {
      NUXT_SANCTUM_PROXY: 'true',
      NUXT_SANCTUM_PROXY_BASE: `http://127.0.0.1:${env.E2E_API_PORT}`,
      NUXT_DEVTOOLS: 'false',
      NUXT_PWA_DEV: 'false',
      FRONTEND_DEV_PORT: env.E2E_WEB_PORT
    }
  })
  await waitFor(`http://127.0.0.1:${env.E2E_WEB_PORT}/login`)
  await run('corepack', ['pnpm', 'exec', 'playwright', 'test', ...playwrightArgs], { cwd: webRoot })
} finally {
  await stop(nuxtProcess)
  if (process.env.E2E_KEEP_STACK !== 'true') {
    await run('docker', [...composeArgs, 'down', '--volumes', '--remove-orphans'])
      .catch(error => console.error(`Falha na limpeza E2E: ${error.message}`))
  }
}
