import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createFiscalApi } from '../../app/composables/api/createFiscalApi'
import type { ApiClient, ApiUrl } from '../../app/composables/api/types'
import type { InstallmentModalityCatalogItem } from '../../app/types/fiscal-modules'
import {
  installmentMonitorFeedback,
  partitionInstallmentCatalog
} from '../../app/utils/installments'
import { fiscalStatusMeta } from '../../app/utils/fiscal-status'

function modality(code: string, executable: boolean): InstallmentModalityCatalogItem {
  return {
    code,
    label: code,
    regime: 'SN',
    official_state: executable ? 'PRODUCTION' : 'PROSPECTION',
    official_state_label: executable ? 'Em produção' : 'Em prospecção',
    monitoring_supported: executable,
    executable,
    required_power: null
  }
}

describe('monitoramento de parcelamentos', () => {
  it('separa as oito modalidades produtivas das duas modalidades em prospecção', () => {
    const items = [
      'PARCSN', 'PARCSN-ESP', 'PERTSN', 'RELPSN',
      'PARCMEI', 'PARCMEI-ESP', 'PERTMEI', 'RELPMEI'
    ].map(code => modality(code, true))
      .concat([modality('PARC-PAEX', false), modality('PARC-SIPADE', false)])

    const availability = partitionInstallmentCatalog(items)

    expect(availability.executable).toHaveLength(8)
    expect(availability.unavailable.map(item => item.code)).toEqual(['PARC-PAEX', 'PARC-SIPADE'])
  })

  it('expõe feedback parcial sem converter falhas em sucesso', () => {
    expect(installmentMonitorFeedback({
      clients: 2,
      requested_modalities_per_client: 8,
      accepted: 13,
      failed: 3,
      results: []
    }, 2)).toMatchObject({
      accepted: 13,
      failed: 3,
      color: 'warning'
    })
  })

  it('contabiliza oito falhas por cliente quando o lote inteiro falha', () => {
    expect(installmentMonitorFeedback(null, 3)).toEqual({
      accepted: 0,
      failed: 24,
      title: 'Nenhuma consulta foi solicitada',
      color: 'error'
    })
  })

  it('usa uma tab por modalidade e mantém PAEX/SIPADE em prospecção bloqueadas', () => {
    const source = readFileSync(
      resolve(process.cwd(), 'app/pages/monitoring/installments.vue'),
      'utf8'
    )
    const kpiSource = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/KpiStrip.vue'),
      'utf8'
    )
    const tabsStart = source.indexOf('<ShellScrollableTabs')
    const tabsMarkup = source.slice(tabsStart, source.indexOf('/>', tabsStart) + 2)
    const kpiTabsStart = kpiSource.indexOf('<ShellScrollableTabs')
    const kpiTabsMarkup = kpiSource.slice(kpiTabsStart, kpiSource.indexOf('/>', kpiTabsStart) + 2)

    for (const code of [
      'PARCSN', 'PARCSN-ESP', 'PERTSN', 'RELPSN',
      'PARCMEI', 'PARCMEI-ESP', 'PERTMEI', 'RELPMEI',
      'PARC-PAEX', 'PARC-SIPADE'
    ]) {
      expect(source).toContain(`code: '${code}'`)
    }
    expect(source).toContain('<ShellScrollableTabs')
    expect(source).toContain('data-testid="installments-modality-control"')
    expect(source).toContain('test-id="installments-type-tabs"')
    for (const markup of [tabsMarkup, kpiTabsMarkup]) {
      expect(markup).toContain('size="md"')
      expect(markup).toContain('class="w-full min-w-0 max-w-full"')
      expect(markup).not.toContain('color=')
      expect(markup).not.toContain('variant=')
      expect(markup).not.toContain(':ui=')
    }
    expect(source).toContain('class="flex w-full min-w-0 max-w-full items-center gap-2"')
    expect(source).toContain('label: \'Todos\'')
    expect(source).toContain('badge: tabBadge(\'all\')')
    expect(source).toContain('badge: tabBadge(item.code)')
    expect(source).toContain('overview.value?.metrics?.tab_counts?.[key]')
    expect(source).toContain('? \'…\' : \'—\'')
    for (const label of [
      'Simples', 'Simples Especial', 'PERT Simples', 'RELP Simples',
      'MEI', 'MEI Especial', 'PERT MEI', 'RELP MEI', 'PAEX', 'SIPADE'
    ]) {
      expect(source).toContain(`: '${label}'`)
    }
    expect(source).toContain('disabled: !item.executable')
    expect(source).toContain('· em prospecção')
    expect(source).toContain('value === \'all\' ? \'all\' : value')
    expect(source).not.toContain('.join(\',\')')
    expect(source).not.toContain('Tipo de parcelamento')
    expect(source).not.toContain('i-lucide-lock-keyhole')
    expect(source).not.toContain('<USelectMenu')
    expect(source).not.toContain('installments-catalog-availability')
    expect(source).not.toContain('sortHeader(\'Situação\'')
    expect(source).toContain('overviewError.value !== loadError.value')
    expect(source).toContain('v-if="distinctOverviewError"')
  })

  it('mantém spine compacta com situação no início e atraso dentro de parcelas', () => {
    const source = readFileSync(
      resolve(process.cwd(), 'app/pages/monitoring/installments.vue'),
      'utf8'
    )

    const situation = source.indexOf('id: \'situation\'')
    const modality = source.indexOf('id: \'modality\'')
    const client = source.indexOf('id: \'client\'')
    const actions = source.indexOf('id: \'actions\'')

    expect(situation).toBeGreaterThan(-1)
    expect(situation).toBeLessThan(modality)
    expect(client).toBeLessThan(actions)
    expect(source).toContain('class: \'text-xs font-medium text-error\'')
    expect(source).toContain('label: \'Ver pedido\'')
    expect(source).not.toContain('id: \'overdue\'')
    expect(source).not.toContain('id: \'guide\'')
  })

  it('traduz situações de parcelamento sem expor rótulos técnicos em inglês', () => {
    expect(fiscalStatusMeta('ACTIVE')).toMatchObject({
      label: 'Ativo',
      color: 'success'
    })
    expect(fiscalStatusMeta('DEFAULT_RISK')).toMatchObject({
      label: 'Risco de inadimplência',
      color: 'warning'
    })
  })

  it('usa o endpoint de lote e confirma o custo de até oito modalidades', () => {
    const source = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/PendingSearchButton.vue'),
      'utf8'
    )

    expect(source).toContain('api.fiscal.installments.monitorAll')
    expect(source).toContain('Consultar todos')
    expect(source).toContain('Até oito consultas compostas por cliente')
  })

  it('integra catálogo e monitoramento em lote aos endpoints fiscais tipados', async () => {
    const catalog = [modality('PARCSN', true)]
    const bulk = {
      clients: 1,
      requested_modalities_per_client: 8,
      accepted: 8,
      failed: 0,
      results: []
    }
    const clientMock = vi.fn()
      .mockResolvedValueOnce({ data: catalog })
      .mockResolvedValueOnce({ data: bulk })
    const api = createFiscalApi(
      clientMock as unknown as ApiClient,
      vi.fn((path: string) => path) as ApiUrl
    )

    await expect(api.fiscal.installments.modalities()).resolves.toEqual({ data: catalog })
    await expect(api.fiscal.installments.monitorAll({
      client_ids: [7],
      correlation_id: 'installments-unit-test'
    })).resolves.toEqual({ data: bulk })

    expect(clientMock).toHaveBeenNthCalledWith(1, '/api/v1/fiscal/installments/modalities')
    expect(clientMock).toHaveBeenNthCalledWith(2, '/api/v1/fiscal/installments/monitor', {
      method: 'POST',
      body: {
        client_ids: [7],
        correlation_id: 'installments-unit-test'
      }
    })
  })

  it('propaga falha da API de monitoramento sem convertê-la em sucesso', async () => {
    const failure = new Error('SERPRO indisponível')
    const clientMock = vi.fn().mockRejectedValue(failure)
    const api = createFiscalApi(
      clientMock as unknown as ApiClient,
      vi.fn((path: string) => path) as ApiUrl
    )

    await expect(api.fiscal.installments.monitorAll({ client_ids: [7] })).rejects.toBe(failure)
  })
})
