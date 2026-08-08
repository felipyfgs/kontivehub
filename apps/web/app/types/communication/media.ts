export interface MediaViewerAttachment {
  id: number
  filename: string
  mime_type: string
  size_bytes: number
  preview_url?: string | null
  download_url?: string | null
}

export interface MediaViewerItem {
  id: string
  conversationId: number
  messageId: number
  attachment: MediaViewerAttachment
}
