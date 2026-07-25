import {
  existsSync,
  readFileSync,
  readdirSync,
  statSync
} from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

interface RegistrySurface {
  surfaceKey: string
  route: string
}

function registrySurfaces(): RegistrySurface[] {
  const source = readFileSync(
    resolve(process.cwd(), '../api/app/Services/FiscalMonitoring/Surfaces/MonitoringSurfaceRegistry.php'),
    'utf8'
  )
  const expression = /new MonitoringSurfaceContract\(\s*surfaceKey:\s*'([^']+)',\s*routePattern:\s*'([^']+)'/gu

  return [...source.matchAll(expression)].map(match => ({
    surfaceKey: match[1] ?? '',
    route: match[2] ?? ''
  }))
}

function routePageCandidates(route: string): string[] {
  const segments = route
    .split('/')
    .filter(Boolean)
    .map(segment => segment.startsWith(':') ? `[${segment.slice(1)}]` : segment)
  const base = resolve(process.cwd(), 'app/pages', ...segments)

  return [`${base}.vue`, resolve(base, 'index.vue')]
}

function sourceFiles(directory: string): string[] {
  return readdirSync(directory).flatMap((name) => {
    const path = resolve(directory, name)
    if (statSync(path).isDirectory()) return sourceFiles(path)
    return /\.(?:ts|vue)$/u.test(path) ? [path] : []
  })
}

describe('gate de equivalência do contrato do monitor', () => {
  it('resolve cada rota canônica do registro Laravel para uma página Nuxt', () => {
    const surfaces = registrySurfaces()

    expect(surfaces.length).toBeGreaterThan(0)
    expect(new Set(surfaces.map(surface => surface.surfaceKey)).size).toBe(surfaces.length)

    for (const surface of surfaces) {
      expect(
        routePageCandidates(surface.route).some(candidate => existsSync(candidate)),
        `${surface.surfaceKey} anuncia uma rota sem página Nuxt: ${surface.route}`
      ).toBe(true)
    }
  })

  it('não mantém uma matriz normativa paralela no frontend', () => {
    const removedMatrix = resolve(process.cwd(), 'app/utils/monitoring-surfaces.ts')
    const applicationSource = sourceFiles(resolve(process.cwd(), 'app'))
      .map(file => readFileSync(file, 'utf8'))
      .join('\n')

    expect(existsSync(removedMatrix)).toBe(false)
    expect(applicationSource).not.toContain('MONITORING_SURFACE_MATRIX')
  })

  it('mantém coverage derivado do registro e o cache protegido por sessão e geração', () => {
    const coverageService = readFileSync(
      resolve(process.cwd(), '../api/app/Services/FiscalMonitoring/Surfaces/MonitoringCoverageService.php'),
      'utf8'
    )
    const workspace = readFileSync(
      resolve(process.cwd(), 'app/composables/useMonitoringWorkspace.ts'),
      'utf8'
    )

    expect(coverageService).toContain('$this->surfaces->all()')
    expect(workspace).toContain('useState<MonitoringCoverageContract | null>')
    expect(workspace).toContain('sessionEpoch')
    expect(workspace).toContain('generation')
    expect(workspace).toContain('filterMonitoringCoverageSurfaces')
  })
})
