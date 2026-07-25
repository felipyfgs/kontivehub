/** Tipos DF-e mais comuns do catálogo Documentos (alinhado a App\Enums\DocumentKind). */

export type DocumentKindCode = 'NFSE' | 'NFE' | 'NFCE' | 'CTE'

export interface DocumentKindMeta {
  code: DocumentKindCode
  label: string
  sefazModel?: string
  captureAvailable: boolean
}

/**
 * Só os mais usados no dia a dia do escritório.
 * captureAvailable é o fallback seguro antes da resposta da API. Tipos sob
 * feature flag só ficam disponíveis quando uma linha do backend confirma isso.
 */
export const DOCUMENT_KINDS: DocumentKindMeta[] = [
  { code: 'NFSE', label: 'NFS-e', captureAvailable: true },
  // NF-e DistDFe operacional (flag SEFAZ_DISTDFE_ENABLED no backend).
  { code: 'NFE', label: 'NF-e', sefazModel: '55', captureAvailable: true },
  // NFC-e: não entra DistDFe de entrada; saída via Captura de saídas / import / recovery SVRS.
  { code: 'NFCE', label: 'NFC-e', sefazModel: '65', captureAvailable: true },
  // CT-e: DistDFe cliente + autXML escritório + import XML/ZIP (flag SEFAZ_CTE_ENABLED no backend).
  { code: 'CTE', label: 'CT-e', sefazModel: '57', captureAvailable: true }
]

/** Rótulo amigável a partir do modelo SEFAZ (import batch / resultados mistos). */
export function documentKindLabelFromModel(model?: string | number | null): string | null {
  const m = String(model ?? '').trim()
  if (m === '55') return 'NF-e'
  if (m === '65') return 'NFC-e'
  if (m === '57') return 'CT-e'
  if (m === '67') return 'CT-e OS (não suportado no catálogo 57)'
  return null
}

const byCode = Object.fromEntries(DOCUMENT_KINDS.map(k => [k.code, k])) as Record<DocumentKindCode, DocumentKindMeta>

export function documentKindMeta(code?: string | null): DocumentKindMeta | null {
  if (!code) return null
  const normalized = code.toUpperCase().replace(/-/g, '_') as DocumentKindCode
  return byCode[normalized] ?? null
}

export function documentKindLabel(code?: string | null): string {
  return documentKindMeta(code)?.label || code || 'Documento'
}

export function isDocumentKindCaptureAvailable(code?: string | null): boolean {
  if (!code || code === 'all') return true
  return documentKindMeta(code)?.captureAvailable ?? false
}

/** Itens para USelect (com "Todos"). */
export function documentKindFilterItems(allLabel = 'Todos os tipos') {
  return [
    { label: allLabel, value: 'all' },
    ...DOCUMENT_KINDS.map(k => ({
      label: k.captureAvailable ? k.label : `${k.label} (em breve)`,
      value: k.code
    }))
  ]
}
