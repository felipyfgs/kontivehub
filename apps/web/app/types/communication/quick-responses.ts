export interface CannedResponse {
  id: number
  title: string
  shortcut: string
  body: string
  is_active: boolean
  lock_version: number
}

/** Params de gestão (GET /communication/canned-responses?manage=1). */
export interface CannedResponseListParams {
  q?: string
  is_active?: boolean
  manage?: boolean | 1 | 0
  page?: number
  per_page?: number
}

export interface CannedResponseWriteBody {
  title: string
  shortcut: string
  body: string
  is_active?: boolean
  lock_version?: number
}

export interface CannedRenderResult {
  body: string
}
