<script setup lang="ts">
import type { PgdasdArtifactDescriptor, PgdasdHistoryPeriod } from '~/types/fiscal-modules'
import { useAuthenticatedDownload } from '~/composables/useAuthenticatedDownload'
import { usePgdasdMonitoring } from '~/composables/usePgdasdMonitoring'
import { resolveApiUrl } from '~/utils/api-url'
import { formatDateTime } from '~/utils/format'
import {
  buildPgdasdHistoryOperationRows,
  pgdasdArtifactLabel,
  type PgdasdHistoryOperationRow
} from '~/utils/pgdasd-history'
import { formatPgdasdPeriod } from '~/utils/pgdasd'

const props = defineProps<{
  period: PgdasdHistoryPeriod
}>()

const { artifactDownloadUrl } = usePgdasdMonitoring()
const { download: downloadAuthenticated, downloading: downloadBusy } = useAuthenticatedDownload()
const apiBase = useRuntimeConfig().public.apiBase as string

const operationModel = computed(() => buildPgdasdHistoryOperationRows(props.period))
const rows = computed(() => operationModel.value.rows)
const otherDocuments = computed(() => operationModel.value.otherDocuments)
const periodTestId = computed(() => `pgdasd-history-period-${props.period.period_key || 'sem-chave'}`)

function declarationDocuments(row: PgdasdHistoryOperationRow): PgdasdArtifactDescriptor[] {
  return [
    ...row.documents.receipt,
    ...row.documents.declaration,
    ...row.documents.maed
  ]
}

function dasDocuments(row: PgdasdHistoryOperationRow): PgdasdArtifactDescriptor[] {
  return [...row.documents.extract, ...row.documents.das]
}

function artifactDownloadPath(artifact: PgdasdArtifactDescriptor): string {
  const path = artifact.download_path?.trim() || artifact.download_href?.trim()
  if (path) return resolveApiUrl(path, apiBase)
  return artifactDownloadUrl(artifact.id)
}

function artifactFilename(artifact: PgdasdArtifactDescriptor): string {
  const filename = artifact.filename?.trim().split(/[\\/]/).pop()
  if (filename) return filename
  const kind = String(artifact.kind || 'documento').toLowerCase().replace(/[^a-z0-9_-]+/gi, '-')
  return `pgdasd-${kind}-${artifact.id}.pdf`
}

async function downloadArtifact(artifact: PgdasdArtifactDescriptor): Promise<void> {
  const path = artifactDownloadPath(artifact)
  if (!path) return
  await downloadAuthenticated(path, artifactFilename(artifact))
}

function yesNoLabel(value?: string | boolean | null): string {
  if (value == null || value === '') return '—'
  if (value === true || ['SIM', 'TRUE', '1'].includes(String(value).toUpperCase())) return 'Sim'
  if (value === false || ['NAO', 'NÃO', 'FALSE', '0'].includes(String(value).toUpperCase())) return 'Não'
  return String(value)
}

function malhaColor(value?: string | boolean | null): 'success' | 'warning' | 'neutral' {
  const label = yesNoLabel(value)
  if (label === 'Sim') return 'warning'
  if (label === 'Não') return 'success'
  return 'neutral'
}

function paymentLabel(value?: boolean | null): string {
  if (value === true) return 'Sim'
  if (value === false) return 'Não'
  return '—'
}

function paymentColor(value?: boolean | null): 'success' | 'warning' | 'neutral' {
  if (value == null) return 'neutral'
  return value ? 'success' : 'warning'
}
</script>

<template>
  <article
    class="w-full min-w-0 max-w-full overflow-hidden rounded-lg border border-default bg-default"
    role="listitem"
    :data-testid="periodTestId"
  >
    <header class="flex min-h-9 flex-col items-stretch justify-between gap-2 bg-success/15 px-3 py-2 sm:flex-row sm:items-center sm:gap-3 sm:px-4">
      <h3 class="text-xs font-semibold text-highlighted">
        PA {{ formatPgdasdPeriod(period.period_key) }}
      </h3>
      <div v-if="$slots.actions" class="w-full shrink-0 sm:w-auto">
        <slot name="actions" />
      </div>
    </header>

    <template v-if="rows.length">
      <div
        class="hidden w-full min-w-0 max-w-full overscroll-x-contain overflow-x-auto xl:block"
        data-testid="pgdasd-history-table"
        role="region"
        :aria-label="`Operações do PA ${formatPgdasdPeriod(period.period_key)}`"
      >
        <table class="w-full min-w-[68rem] border-separate border-spacing-0 text-left text-sm">
          <thead class="text-xs text-muted">
            <tr class="bg-elevated/70">
              <th rowspan="2" class="border-b border-default px-3 py-2.5 text-center font-semibold text-highlighted">
                Operação
              </th>
              <th colspan="4" class="border-b border-l border-default px-3 py-2.5 text-center font-semibold text-highlighted">
                Declaração
              </th>
              <th rowspan="2" class="border-b border-l border-default px-3 py-2.5 text-center font-semibold text-highlighted">
                Malha
              </th>
              <th rowspan="2" class="border-b border-default px-3 py-2.5 text-center font-semibold text-highlighted">
                MAED
              </th>
              <th colspan="4" class="border-b border-l border-default px-3 py-2.5 text-center font-semibold text-highlighted">
                DAS
              </th>
            </tr>
            <tr class="bg-elevated/40">
              <th class="border-b border-l border-default px-3 py-2 font-medium">
                Nº Declaração
              </th>
              <th class="border-b border-default px-3 py-2 font-medium">
                Data/hora Transmissão
              </th>
              <th class="border-b border-default px-3 py-2 text-center font-medium">
                Recibo
              </th>
              <th class="border-b border-default px-3 py-2 text-center font-medium">
                Declaração
              </th>
              <th class="border-b border-l border-default px-3 py-2 font-medium">
                Nº DAS
              </th>
              <th class="border-b border-default px-3 py-2 font-medium">
                Data/hora Emissão
              </th>
              <th class="border-b border-default px-3 py-2 text-center font-medium">
                Extrato
              </th>
              <th class="border-b border-default px-3 py-2 text-center font-medium">
                Pago
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.key" class="group">
              <td class="border-b border-default px-3 py-3 group-hover:bg-elevated/40">
                <span class="font-medium text-highlighted">
                  {{ row.operationLabel }}
                </span>
              </td>
              <td class="border-b border-l border-default px-3 py-3 font-mono tabular-nums group-hover:bg-elevated/40">
                {{ row.declarationNumber || '—' }}
              </td>
              <td class="whitespace-nowrap border-b border-default px-3 py-3 group-hover:bg-elevated/40">
                {{ formatDateTime(row.transmittedAt) }}
              </td>
              <td class="border-b border-default px-3 py-3 text-center group-hover:bg-elevated/40">
                <div v-if="row.documents.receipt.length" class="flex justify-center gap-1">
                  <UButton
                    v-for="artifact in row.documents.receipt"
                    :key="artifact.id"
                    size="xs"
                    color="neutral"
                    variant="soft"
                    icon="i-lucide-download"
                    square
                    :aria-label="`Baixar ${pgdasdArtifactLabel(artifact.kind)}`"
                    :title="`Baixar ${pgdasdArtifactLabel(artifact.kind)}`"
                    :loading="downloadBusy"
                    :disabled="downloadBusy"
                    @click="downloadArtifact(artifact)"
                  />
                </div>
                <span v-else class="text-muted">—</span>
              </td>
              <td class="border-b border-default px-3 py-3 text-center group-hover:bg-elevated/40">
                <div v-if="row.documents.declaration.length" class="flex justify-center gap-1">
                  <UButton
                    v-for="artifact in row.documents.declaration"
                    :key="artifact.id"
                    size="xs"
                    color="neutral"
                    variant="soft"
                    icon="i-lucide-download"
                    square
                    :aria-label="`Baixar ${pgdasdArtifactLabel(artifact.kind)}`"
                    :title="`Baixar ${pgdasdArtifactLabel(artifact.kind)}`"
                    :loading="downloadBusy"
                    :disabled="downloadBusy"
                    @click="downloadArtifact(artifact)"
                  />
                </div>
                <span v-else class="text-muted">—</span>
              </td>
              <td class="border-b border-default px-3 py-3 text-center group-hover:bg-elevated/40">
                {{ row.kind === 'declaration' ? yesNoLabel(row.malha) : '—' }}
              </td>
              <td class="border-b border-default px-3 py-3 text-center group-hover:bg-elevated/40">
                <div v-if="row.documents.maed.length" class="flex justify-center gap-1">
                  <UButton
                    v-for="artifact in row.documents.maed"
                    :key="artifact.id"
                    size="xs"
                    color="neutral"
                    variant="soft"
                    icon="i-lucide-download"
                    square
                    :aria-label="`Baixar ${pgdasdArtifactLabel(artifact.kind)}`"
                    :title="`Baixar ${pgdasdArtifactLabel(artifact.kind)}`"
                    :loading="downloadBusy"
                    :disabled="downloadBusy"
                    @click="downloadArtifact(artifact)"
                  />
                </div>
                <span v-else class="text-muted">—</span>
              </td>
              <td class="border-b border-l border-default px-3 py-3 font-mono tabular-nums group-hover:bg-elevated/40">
                {{ row.dasNumber || '—' }}
              </td>
              <td class="whitespace-nowrap border-b border-default px-3 py-3 group-hover:bg-elevated/40">
                {{ formatDateTime(row.issuedAt) }}
              </td>
              <td class="border-b border-default px-3 py-3 text-center group-hover:bg-elevated/40">
                <div v-if="dasDocuments(row).length" class="flex justify-center gap-1">
                  <UButton
                    v-for="artifact in dasDocuments(row)"
                    :key="artifact.id"
                    size="xs"
                    color="neutral"
                    variant="soft"
                    icon="i-lucide-download"
                    square
                    :aria-label="`Baixar ${pgdasdArtifactLabel(artifact.kind)}`"
                    :title="`Baixar ${pgdasdArtifactLabel(artifact.kind)}`"
                    :loading="downloadBusy"
                    :disabled="downloadBusy"
                    @click="downloadArtifact(artifact)"
                  />
                </div>
                <span v-else class="text-muted">—</span>
              </td>
              <td class="border-b border-default px-3 py-3 text-center group-hover:bg-elevated/40">
                {{ row.kind === 'das' ? paymentLabel(row.paymentLocated) : '—' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <ul class="min-w-0 space-y-2 p-3 xl:hidden" data-testid="pgdasd-history-mobile">
        <li
          v-for="row in rows"
          :key="row.key"
          class="rounded-md border border-default bg-elevated/30 p-3"
        >
          <p class="text-sm font-semibold text-highlighted">
            {{ row.operationLabel }}
          </p>

          <dl v-if="row.kind === 'declaration'" class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
            <div>
              <dt class="text-muted">
                Nº declaração
              </dt>
              <dd class="mt-0.5 break-all font-mono tabular-nums text-highlighted">
                {{ row.declarationNumber || '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Transmissão
              </dt>
              <dd class="mt-0.5 text-highlighted">
                {{ formatDateTime(row.transmittedAt) }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Malha
              </dt>
              <dd class="mt-1">
                <UBadge
                  :color="malhaColor(row.malha)"
                  variant="subtle"
                  size="sm"
                  :label="yesNoLabel(row.malha)"
                />
              </dd>
            </div>
          </dl>

          <dl v-else class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
            <div>
              <dt class="text-muted">
                Nº DAS
              </dt>
              <dd class="mt-0.5 break-all font-mono tabular-nums text-highlighted">
                {{ row.dasNumber || '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Emissão
              </dt>
              <dd class="mt-0.5 text-highlighted">
                {{ formatDateTime(row.issuedAt) }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Pago
              </dt>
              <dd class="mt-1">
                <UBadge
                  :color="paymentColor(row.paymentLocated)"
                  variant="subtle"
                  size="sm"
                  :label="paymentLabel(row.paymentLocated)"
                />
              </dd>
            </div>
          </dl>

          <div v-if="declarationDocuments(row).length || dasDocuments(row).length" class="mt-3 flex flex-wrap gap-2 border-t border-default pt-3">
            <UButton
              v-for="artifact in [...declarationDocuments(row), ...dasDocuments(row)]"
              :key="artifact.id"
              size="xs"
              color="neutral"
              variant="soft"
              icon="i-lucide-download"
              :label="pgdasdArtifactLabel(artifact.kind)"
              :loading="downloadBusy"
              :disabled="downloadBusy"
              @click="downloadArtifact(artifact)"
            />
          </div>
        </li>
      </ul>
    </template>

    <p
      v-else
      class="px-4 py-8 text-center text-sm text-muted"
      data-testid="pgdasd-history-period-empty"
    >
      Sem registros neste PA.
    </p>

    <section v-if="otherDocuments.length" class="border-t border-default px-3 py-3 sm:px-4">
      <h4 class="text-xs font-medium uppercase tracking-wide text-muted">
        Outros documentos
      </h4>
      <div class="mt-2 flex flex-wrap gap-2">
        <UButton
          v-for="artifact in otherDocuments"
          :key="artifact.id"
          size="xs"
          color="neutral"
          variant="soft"
          icon="i-lucide-download"
          :label="pgdasdArtifactLabel(artifact.kind)"
          :aria-label="`Baixar ${pgdasdArtifactLabel(artifact.kind)} de outros documentos`"
          :loading="downloadBusy"
          :disabled="downloadBusy"
          @click="downloadArtifact(artifact)"
        />
      </div>
    </section>
  </article>
</template>
