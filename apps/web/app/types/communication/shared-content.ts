export type SharedContentCategory = 'media' | 'links' | 'documents'

/** Item allowlisted da galeria de conteúdo compartilhado. Não inclui corpo ou paths internos. */
export interface SharedContentItem {
  id: string
  type: 'attachment' | 'link'
  category: SharedContentCategory
  conversation_id: number
  message_id: number
  occurred_at?: string | null
  attachment?: {
    id: number
    filename: string
    mime_type: string
    size_bytes: number
    preview_url?: string | null
    download_url?: string | null
  } | null
  link?: {
    url: string
    title?: string | null
    description?: string | null
  } | null
}

export interface SharedContentMeta {
  next_cursor: string | null
  snapshot_through_message_id: number | null
  snapshot_through_attachment_id: number | null
  limit: number
}
