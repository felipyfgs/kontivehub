#!/usr/bin/env node
/**
 * Encerra processos de browser/MCP de dev que costumam ficar órfãos no VPS
 * (playwright-mcp, chrome headless do Playwright, vite preview de e2e).
 *
 * Não mexe em: Docker stack (nginx/php/postgres), Nuxt dev do compose, VS Code.
 *
 * Uso: pnpm run cleanup:browsers
 *      CLEANUP_DRY_RUN=1 pnpm run cleanup:browsers
 */
import { execFileSync } from 'node:child_process'

const dryRun = ['1', 'true', 'yes'].includes(
  String(process.env.CLEANUP_DRY_RUN || '').toLowerCase()
)

const selfPid = process.pid
const parentPid = process.ppid

/** Substring no cmdline (case-sensitive). */
const patterns = [
  'playwright-mcp',
  '@playwright/mcp',
  'playwright_chromiumdev_profile',
  'user-data-dir=/tmp/playwright_chromium',
  'chrome-devtools-mcp',
  'vite preview --host 127.0.0.1 --port 4173'
]

/** Não matar se o cmdline for só tooling de listagem/este script. */
function isMetaProcess(cmd) {
  if (cmd.includes('cleanup-dev-browsers')) return true
  if (/\bpgrep\b/.test(cmd) || /\bpkill\b/.test(cmd)) return true
  if (cmd.includes('dump_bash_state')) return true
  return false
}

function listAll() {
  try {
    // pid + full cmdline
    return execFileSync('ps', ['-eo', 'pid=,args='], { encoding: 'utf8' })
      .split('\n')
      .map(line => line.trim())
      .filter(Boolean)
      .map((line) => {
        const m = line.match(/^(\d+)\s+(.*)$/)
        if (!m) return null
        return { pid: Number(m[1]), cmd: m[2] }
      })
      .filter(Boolean)
  } catch {
    return []
  }
}

function matchesPattern(cmd, pattern) {
  return cmd.includes(pattern)
}

const all = listAll()
const victims = new Map()

for (const pattern of patterns) {
  for (const proc of all) {
    if (proc.pid === selfPid || proc.pid === parentPid) continue
    if (!matchesPattern(proc.cmd, pattern)) continue
    if (isMetaProcess(proc.cmd)) continue
    victims.set(proc.pid, { ...proc, pattern })
  }
}

console.log(dryRun ? 'cleanup-dev-browsers (DRY RUN)' : 'cleanup-dev-browsers')

if (victims.size === 0) {
  console.log('Nenhum processo órfão de browser/MCP encontrado.')
  process.exit(0)
}

for (const { pid, cmd, pattern } of victims.values()) {
  const short = cmd.length > 140 ? `${cmd.slice(0, 140)}…` : cmd
  console.log(`[${pattern}] pid=${pid}  ${short}`)
}

if (dryRun) {
  console.log(`Dry-run: ${victims.size} processo(s). Rode sem CLEANUP_DRY_RUN para encerrar.`)
  process.exit(0)
}

let killed = 0
for (const pid of victims.keys()) {
  try {
    process.kill(pid, 'SIGTERM')
    killed++
  } catch {
    /* already gone */
  }
}

// grace + SIGKILL residual
execFileSync('sleep', ['1'])
for (const pid of victims.keys()) {
  try {
    process.kill(pid, 0)
    process.kill(pid, 'SIGKILL')
  } catch {
    /* gone */
  }
}

console.log(`Sinal enviado a ${killed} processo(s). Confira: free -h`)
process.exit(0)
