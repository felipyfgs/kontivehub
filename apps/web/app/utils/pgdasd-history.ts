import type {
  PgdasdArtifactDescriptor,
  PgdasdHistoryDeclaration,
  PgdasdHistoryPeriod
} from '~/types/fiscal-modules'

export interface PgdasdHistoryOperationDocuments {
  receipt: PgdasdArtifactDescriptor[]
  declaration: PgdasdArtifactDescriptor[]
  maed: PgdasdArtifactDescriptor[]
  extract: PgdasdArtifactDescriptor[]
  das: PgdasdArtifactDescriptor[]
}

export interface PgdasdHistoryOperationRow {
  key: string
  kind: 'declaration' | 'das'
  operationLabel: string
  declarationNumber: string | null
  transmittedAt: string | null
  malha: string | boolean | null
  dasNumber: string | null
  issuedAt: string | null
  paymentLocated: boolean | null
  documents: PgdasdHistoryOperationDocuments
}

export interface PgdasdHistoryOperationRows {
  rows: PgdasdHistoryOperationRow[]
  otherDocuments: PgdasdArtifactDescriptor[]
}

const DECLARATION_DOCUMENT_GROUPS = {
  RECIBO: 'receipt',
  DECLARACAO: 'declaration',
  NOTIFICACAO_MAED: 'maed',
  DARF_MAED: 'maed',
  MAED: 'maed'
} as const

const DAS_DOCUMENT_GROUPS = {
  EXTRATO: 'extract',
  DAS: 'das'
} as const

function normalizeIdentifier(value?: string | null): string | null {
  const normalized = value?.trim()
  return normalized || null
}

function artifactIdentity(artifact: PgdasdArtifactDescriptor): string {
  return `artifact-${artifact.id}`
}

function emptyDocuments(): PgdasdHistoryOperationDocuments {
  return {
    receipt: [],
    declaration: [],
    maed: [],
    extract: [],
    das: []
  }
}

function collectPeriodArtifacts(period: PgdasdHistoryPeriod): PgdasdArtifactDescriptor[] {
  const artifacts = [
    ...(period.artifacts || []),
    ...(period.documents || []),
    ...(period.declarations || []).flatMap(item => item.documents || []),
    ...(period.das || []).flatMap(item => item.documents || [])
  ]

  return [...new Map(artifacts.map(artifact => [artifactIdentity(artifact), artifact])).values()]
}

function declarationOperationLabel(declaration: PgdasdHistoryDeclaration): string {
  const raw = declaration.normalized_operation_type || declaration.operation_type
  if (!raw) return 'Declaração'

  const normalized = raw.trim().toUpperCase().replaceAll('-', '_').replaceAll(' ', '_')
  if (['ORIGINAL', 'DECLARACAO_ORIGINAL'].includes(normalized)) {
    return 'Declaração Original'
  }
  if (['RECTIFIER', 'RECTIFYING', 'RETIFICADORA', 'DECLARACAO_RETIFICADORA'].includes(normalized)) {
    return 'Declaração Retificadora'
  }
  if (raw !== raw.toUpperCase()) return raw

  const readable = raw.replaceAll('_', ' ').toLocaleLowerCase('pt-BR')
  return readable.charAt(0).toLocaleUpperCase('pt-BR') + readable.slice(1)
}

function declarationOperationRank(declaration: PgdasdHistoryDeclaration): number {
  const raw = declaration.normalized_operation_type || declaration.operation_type || ''
  const normalized = raw.trim().toUpperCase().replaceAll('-', '_').replaceAll(' ', '_')
  if (['ORIGINAL', 'DECLARACAO_ORIGINAL'].includes(normalized)) return 0
  if (['RECTIFIER', 'RECTIFYING', 'RETIFICADORA', 'DECLARACAO_RETIFICADORA'].includes(normalized)) return 1
  return 2
}

function dasOperationLabel(raw?: string | null): string {
  if (!raw) return 'Geração de DAS'

  const normalized = raw.trim().toUpperCase().replaceAll('-', '_').replaceAll(' ', '_')
  if (['DAS', 'DAS_GENERATION', 'GENERATION_OF_DAS', 'GERACAO_DE_DAS'].includes(normalized)) {
    return 'Geração de DAS'
  }
  if (raw !== raw.toUpperCase()) return raw

  const readable = raw.replaceAll('_', ' ').toLocaleLowerCase('pt-BR')
  return readable.charAt(0).toLocaleUpperCase('pt-BR') + readable.slice(1)
}

export function pgdasdArtifactLabel(kind?: string | null): string {
  return {
    DECLARACAO: 'Declaração',
    RECIBO: 'Recibo',
    NOTIFICACAO_MAED: 'MAED',
    DARF_MAED: 'DAS da MAED',
    MAED: 'MAED',
    EXTRATO: 'Extrato',
    DAS: 'DAS'
  }[String(kind || '').toUpperCase()] || 'Documento'
}

export function buildPgdasdHistoryOperationRows(
  period: PgdasdHistoryPeriod
): PgdasdHistoryOperationRows {
  const artifacts = collectPeriodArtifacts(period)
  const assignedArtifacts = new Set<string>()

  const declarations = [...(period.declarations || [])].sort((a, b) => {
    const byOperation = declarationOperationRank(a) - declarationOperationRank(b)
    if (byOperation !== 0) return byOperation
    const byDate = String(a.transmitted_at || '').localeCompare(String(b.transmitted_at || ''))
    if (byDate !== 0) return byDate
    return String(a.declaration_number || a.number || '')
      .localeCompare(String(b.declaration_number || b.number || ''))
  })

  const declarationRows: PgdasdHistoryOperationRow[] = declarations.map((declaration, index) => {
    const declarationNumber = normalizeIdentifier(
      declaration.declaration_number || declaration.number
    )
    const documents = emptyDocuments()

    if (declarationNumber) {
      for (const artifact of artifacts) {
        const group = DECLARATION_DOCUMENT_GROUPS[String(artifact.kind || '').toUpperCase() as keyof typeof DECLARATION_DOCUMENT_GROUPS]
        if (!group || normalizeIdentifier(artifact.declaration_number) !== declarationNumber) continue
        documents[group].push(artifact)
        assignedArtifacts.add(artifactIdentity(artifact))
      }
    }

    return {
      key: `declaration-${declaration.id ?? declarationNumber ?? index}`,
      kind: 'declaration',
      operationLabel: declarationOperationLabel(declaration),
      declarationNumber,
      transmittedAt: declaration.transmitted_at || null,
      malha: declaration.malha ?? null,
      dasNumber: null,
      issuedAt: null,
      paymentLocated: null,
      documents
    }
  })

  const dasItems = [...(period.das || [])].sort((a, b) => {
    const byDate = String(a.issued_at || '').localeCompare(String(b.issued_at || ''))
    if (byDate !== 0) return byDate
    return String(a.das_number || '').localeCompare(String(b.das_number || ''))
  })

  const dasRows: PgdasdHistoryOperationRow[] = dasItems.map((das, index) => {
    const dasNumber = normalizeIdentifier(das.das_number)
    const documents = emptyDocuments()

    if (dasNumber) {
      for (const artifact of artifacts) {
        const group = DAS_DOCUMENT_GROUPS[String(artifact.kind || '').toUpperCase() as keyof typeof DAS_DOCUMENT_GROUPS]
        if (!group || normalizeIdentifier(artifact.das_number) !== dasNumber) continue
        documents[group].push(artifact)
        assignedArtifacts.add(artifactIdentity(artifact))
      }
    }

    return {
      key: `das-${das.id ?? dasNumber ?? index}`,
      kind: 'das',
      operationLabel: dasOperationLabel(das.normalized_operation_type),
      declarationNumber: null,
      transmittedAt: null,
      malha: null,
      dasNumber,
      issuedAt: das.issued_at || null,
      paymentLocated: das.payment_located ?? null,
      documents
    }
  })

  return {
    rows: [...declarationRows, ...dasRows],
    otherDocuments: artifacts.filter(artifact => !assignedArtifacts.has(artifactIdentity(artifact)))
  }
}
