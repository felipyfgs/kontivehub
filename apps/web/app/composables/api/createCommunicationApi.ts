import type { AutomationMeta, AutomationPolicy, RecipientConfiguration, RecipientMode } from '~/types/communication/automation'
import type { BulkAction, BulkItemStatus, BulkOperation, BulkOperationItemsMeta, BulkOperationParams, BulkOperationResultItem, BulkOperationSubmitItem, Conversation, ConversationListMeta, ConversationListPreferences, ConversationTimelineMeta, ConversationSortBy, ConversationStatus, ListPreferenceStatus, ConversationInitiation, OutboundCapabilities } from '~/types/communication/conversations'
import type { CannedRenderResult, CannedResponse, CannedResponseListParams, CannedResponseWriteBody } from '~/types/communication/quick-responses'
import type { Contact, ContactListParams, Label } from '~/types/communication/contacts'
import type { Event, SyncMeta } from '~/types/communication/realtime'
import type { FeatureMeta, Inbox, PairingState, QueuedCommand, SessionStatus } from '~/types/communication/inboxes'
import type { Message, SendKind } from '~/types/communication/messages'
import type { SharedContentCategory, SharedContentItem, SharedContentMeta } from '~/types/communication/shared-content'
import type { ComposerDraftApiRequest } from '~/utils/communication-composer-draft-api'
import type {
  StickerLibraryFilter,
  StickerLibraryItem,
  StickerLibraryListResponse
} from '~/types/communication/sticker-library'
import type {
  Flow,
  FlowBinding,
  FlowDraft,
  FlowDryRunContext,
  FlowDryRunResult,
  FlowGraph,
  FlowListMeta,
  FlowPreviewResult,
  FlowPublishResult,
  FlowRun,
  FlowStatus,
  FlowValidateResult
} from '~/types/communication/flows'
import { isSensitiveCommunicationContactSearch } from '~/utils/communication-contacts'
import type { ApiClient, ApiUrl } from './types'

export interface ConversationFilters {
  q?: string
  inbox_id?: number
  status?: ConversationStatus
  assignee_membership_id?: number
  work_department_id?: number
  unassigned?: boolean
  unread?: boolean
  /** OR entre rótulos válidos do tenant. */
  label_ids?: number[]
  contact_id?: number
  sort_by?: ConversationSortBy
  page?: number
  per_page?: number
  snapshot?: boolean
  snapshot_token?: string
}

export interface BulkOperationCreateBody {
  action: BulkAction
  params?: BulkOperationParams
  items: BulkOperationSubmitItem[]
}

export interface PolicyBody {
  module_key: string
  submodule_key: string
  inbox_id: number | null
  is_enabled: boolean
  send_day: number
  send_time: string
  timezone: string
  recipient_mode: RecipientMode
  template_key: string
  template_version: string
  lock_version: number
}

export function createCommunicationApi(client: ApiClient, apiUrl: ApiUrl) {
  const base = '/api/v1/communication'

  function validStickerId(id: string): boolean {
    return /^[A-Za-z0-9_-]{8,128}$/.test(id)
  }

  return {
    communication: {
      inboxes: {
        list: () => client<{ data: Inbox[], meta: FeatureMeta }>(`${base}/inboxes`),
        create: (body: {
          name: string
          is_enabled?: boolean
          is_default?: boolean
          work_department_id?: number | null
        }) => client<{ data: Inbox }>(`${base}/inboxes`, { method: 'POST', body }),
        update: (id: number, body: Partial<Pick<Inbox,
          'name' | 'is_enabled' | 'is_default' | 'work_department_id'>> & { lock_version: number }) =>
          client<{ data: Inbox }>(`${base}/inboxes/${id}`, { method: 'PATCH', body }),
        remove: (id: number) =>
          client<{ data: QueuedCommand & { deleted: boolean } }>(`${base}/inboxes/${id}`, {
            method: 'DELETE'
          }),
        replaceMembers: (id: number, membershipIds: number[]) =>
          client<{ data: { membership_ids: number[] } }>(`${base}/inboxes/${id}/members`, {
            method: 'PUT',
            body: { membership_ids: membershipIds }
          }),
        connect: (id: number) =>
          client<{ data: PairingState }>(`${base}/inboxes/${id}/session/connect`, {
            method: 'POST'
          }),
        disconnect: (id: number) =>
          client<{ data: QueuedCommand }>(`${base}/inboxes/${id}/session/disconnect`, {
            method: 'POST'
          }),
        logout: (id: number) =>
          client<{ data: QueuedCommand }>(`${base}/inboxes/${id}/session/logout`, {
            method: 'POST'
          }),
        sessionStatus: (id: number) =>
          client<{ data: SessionStatus }>(`${base}/inboxes/${id}/session/status`),
        updateTenantSettings: (enabled: boolean) =>
          client<{ data: { enabled: boolean } }>(`${base}/settings`, {
            method: 'PATCH',
            body: { enabled }
          })
      },
      contacts: {
        list: (params?: ContactListParams) => {
          const sensitiveSearch = isSensitiveCommunicationContactSearch(params?.q)
          return client<{ data: Contact[], meta: { current_page: number, last_page: number, total: number } }>(
            sensitiveSearch ? `${base}/contacts/search` : `${base}/contacts`,
            sensitiveSearch
              ? { method: 'POST', body: params }
              : { query: params }
          )
        },
        get: (id: number, inboxId?: number) => client<{ data: Contact }>(
          `${base}/contacts/${id}`,
          inboxId ? { query: { inbox_id: inboxId } } : undefined
        ),
        create: (body: {
          name?: string | null
          phone: string
          client_id?: number
          client_contact_id?: number
          is_primary?: boolean
          receives_automatic?: boolean
        }) => client<{ data: Contact }>(`${base}/contacts`, { method: 'POST', body }),
        update: (id: number, body: { name?: string | null, is_active?: boolean }) =>
          client<{ data: Contact }>(`${base}/contacts/${id}`, { method: 'PATCH', body }),
        addIdentity: (contactId: number, phone: string) =>
          client<{ data: { id: number, address_masked: string } }>(`${base}/contacts/${contactId}/identities`, {
            method: 'POST',
            body: { phone }
          }),
        linkIdentity: (identityId: number, body: {
          client_id: number
          client_contact_id?: number | null
          is_primary?: boolean
          receives_automatic?: boolean
        }) => client<{ data: {
          id: number
          identity_id: number
          client_id: number
          client_contact_id?: number | null
          is_primary: boolean
          receives_automatic: boolean
        } }>(`${base}/identities/${identityId}/links`, { method: 'POST', body }),
        unlinkIdentity: (identityId: number, linkId: number) =>
          client<unknown>(`${base}/identities/${identityId}/links/${linkId}`, { method: 'DELETE' }),
        exportUrl: (contactId: number) => apiUrl(`${base}/contacts/${contactId}/export`),
        purge: (contactId: number) => client<{ data: {
          contact_id: number
          purged_at: string
          deleted_blobs: number
          tombstone_digest: string
        } }>(`${base}/contacts/${contactId}/personal-data`, { method: 'DELETE' }),
        sharedContent: (contactId: number, params: {
          category: SharedContentCategory
          cursor?: string
          limit?: number
          inbox_id?: number
        }) => client<{ data: SharedContentItem[], meta: SharedContentMeta }>(
          `${base}/contacts/${contactId}/shared-content`, { query: params }
        )
      },
      conversationListPreferences: {
        get: () =>
          client<{ data: ConversationListPreferences }>(
            `${base}/conversation-list-preferences`
          ),
        update: (body: {
          status: ListPreferenceStatus
          sort_by: ConversationSortBy
        }) =>
          client<{ data: ConversationListPreferences }>(
            `${base}/conversation-list-preferences`,
            {
              method: 'PUT',
              body
            }
          )
      },
      conversationBulkOperations: {
        create: (
          body: BulkOperationCreateBody,
          idempotencyKey: string
        ) =>
          client<{ data: BulkOperation }>(
            `${base}/conversation-bulk-operations`,
            {
              method: 'POST',
              body,
              headers: { 'Idempotency-Key': idempotencyKey }
            }
          ),
        get: (operationId: string) =>
          client<{ data: BulkOperation }>(
            `${base}/conversation-bulk-operations/${operationId}`
          ),
        items: (
          operationId: string,
          params?: {
            status?: BulkItemStatus
            page?: number
            per_page?: number
          }
        ) =>
          client<{
            data: BulkOperationResultItem[]
            meta: BulkOperationItemsMeta
          }>(`${base}/conversation-bulk-operations/${operationId}/items`, {
            query: params
          })
      },
      conversations: {
        create: (body: {
          contact_id: number
          identity_id: number
          inbox_id: number
          body?: string
          file?: File | null
          kind?: SendKind
          ptt?: boolean
        }, idempotencyKey: string) => {
          const payload = new FormData()
          payload.set('contact_id', String(body.contact_id))
          payload.set('identity_id', String(body.identity_id))
          payload.set('inbox_id', String(body.inbox_id))
          if (body.body?.trim()) payload.set('body', body.body.trim())
          if (body.kind) payload.set('kind', body.kind)
          if (body.ptt) payload.set('ptt', '1')
          if (body.file) payload.set('file', body.file, body.file.name)
          return client<{ data: ConversationInitiation }>(`${base}/conversations`, {
            method: 'POST', body: payload, headers: { 'Idempotency-Key': idempotencyKey }
          })
        },
        list: (
          params?: ConversationFilters,
          options?: { signal?: AbortSignal }
        ) =>
          client<{ data: Conversation[], meta: ConversationListMeta }>(
            `${base}/conversations`,
            { query: params, signal: options?.signal }
          ),
        get: (id: number, params?: { include_messages?: boolean }) =>
          client<{ data: Conversation }>(`${base}/conversations/${id}`, {
            query: params?.include_messages === undefined
              ? undefined
              : { include_messages: params.include_messages ? 1 : 0 }
          }),
        messages: (id: number, params?: {
          limit?: number
          cursor?: string
          anchor?: 'latest' | 'first_unread' | 'message'
          message_id?: number
        }) =>
          client<{
            data: Message[]
            meta: ConversationTimelineMeta
          }>(`${base}/conversations/${id}/messages`, { query: params }),
        sharedContent: (id: number, params: {
          category: SharedContentCategory
          cursor?: string
          limit?: number
        }) => client<{ data: SharedContentItem[], meta: SharedContentMeta }>(
          `${base}/conversations/${id}/shared-content`, { query: params }
        ),
        saveSharedContact: (
          conversationId: number,
          messageId: number,
          contactIndex: number,
          phoneIndex: number
        ) => client<{ data: { outcome: 'created' | 'existing', contact: Contact } }>(
          `${base}/conversations/${conversationId}/messages/${messageId}/contacts/${contactIndex}/save`,
          { method: 'POST', body: { phone_index: phoneIndex } }
        ),
        updateReadState: (id: number, body:
          | { state: 'READ', through_message_id: number }
          | { state: 'UNREAD', expected_version: number }
        ) =>
          client<{ data: Conversation }>(`${base}/conversations/${id}/read-state`, {
            method: 'PUT',
            body
          }),
        markRead: (id: number, body: { through_message_id: number }) =>
          client<{ data: Conversation }>(`${base}/conversations/${id}/read-state`, {
            method: 'PUT',
            body: { state: 'READ', through_message_id: body.through_message_id }
          }),
        markUnread: (id: number, body: { expected_version: number }) =>
          client<{ data: Conversation }>(`${base}/conversations/${id}/read-state`, {
            method: 'PUT',
            body: { state: 'UNREAD', expected_version: body.expected_version }
          }),
        update: (id: number, body: {
          lock_version: number
          status?: ConversationStatus
          assignee_membership_id?: number | null
          work_department_id?: number | null
          priority?: number
          snoozed_until?: string | null
        }) => client<{ data: Conversation }>(`${base}/conversations/${id}`, {
          method: 'PATCH',
          body
        }),
        send: (id: number, body: {
          body: string
          internal_note?: boolean
          reply_to_message_id?: number | null
          idempotency_key?: string
          file?: File | null
          kind?: SendKind
          ptt?: boolean
          receipt_message_id?: number
        }) => {
          const payload = new FormData()
          payload.set('body', body.body)
          if (body.internal_note) payload.set('internal_note', '1')
          if (body.reply_to_message_id) payload.set('reply_to_message_id', String(body.reply_to_message_id))
          if (body.idempotency_key) payload.set('idempotency_key', body.idempotency_key)
          if (body.kind) payload.set('kind', body.kind)
          if (body.ptt) payload.set('ptt', '1')
          if (body.receipt_message_id) {
            payload.set('receipt_message_id', String(body.receipt_message_id))
          }
          if (body.file) payload.set('file', body.file, body.file.name)
          return client<{ data: Message }>(`${base}/conversations/${id}/messages`, {
            method: 'POST',
            body: payload
          })
        },
        sendDraft: (id: number, request: ComposerDraftApiRequest) => client<{
          data: Message | { messages?: Message[] }
        }>(request.path.includes('/message-batches')
          ? `${base}/conversations/${id}/message-batches`
          : `${base}/conversations/${id}/messages`, {
          method: 'POST',
          body: request.body,
          headers: request.headers
        }),
        editMessage: (conversationId: number, messageId: number, text: string) =>
          client<{ data: QueuedCommand }>(
            `${base}/conversations/${conversationId}/messages/${messageId}/edit`,
            { method: 'PUT', body: { text } }
          ),
        revokeMessage: (conversationId: number, messageId: number) =>
          client<{ data: QueuedCommand }>(
            `${base}/conversations/${conversationId}/messages/${messageId}`,
            { method: 'DELETE' }
          ),
        reactMessage: (conversationId: number, messageId: number, emoji: string | null) =>
          client<{ data: QueuedCommand }>(
            `${base}/conversations/${conversationId}/messages/${messageId}/reaction`,
            { method: 'PUT', body: { emoji } }
          ),
        votePoll: (conversationId: number, messageId: number, optionNames: string[]) =>
          client<{ data: QueuedCommand }>(
            `${base}/conversations/${conversationId}/messages/${messageId}/poll-votes`,
            { method: 'POST', body: { option_names: optionNames } }
          ),
        receipt: (conversationId: number, messageId: number, receipt: 'READ' | 'PLAYED') =>
          client<{ data: QueuedCommand }>(
            `${base}/conversations/${conversationId}/messages/${messageId}/receipts`,
            { method: 'POST', body: { receipt } }
          ),
        recoverMessage: (
          conversationId: number,
          messageId: number,
          operation: 'UNAVAILABLE' | 'MEDIA_RETRY'
        ) => client<{ data: QueuedCommand }>(
          `${base}/conversations/${conversationId}/messages/${messageId}/recovery`,
          { method: 'POST', body: { operation } }
        ),
        subscribePresence: (conversationId: number) =>
          client<{ data: QueuedCommand }>(
            `${base}/conversations/${conversationId}/presence/subscribe`,
            { method: 'POST' }
          ),
        setPresence: (
          conversationId: number,
          presence: 'COMPOSING' | 'PAUSED' | 'RECORDING',
          media?: 'TEXT' | 'AUDIO'
        ) => client<{ data: QueuedCommand }>(
          `${base}/conversations/${conversationId}/presence`,
          { method: 'PUT', body: { presence, ...(media ? { media } : {}) } }
        ),
        setDisappearing: (
          conversationId: number,
          timerSeconds: 0 | 86400 | 604800 | 7776000
        ) => client<{ data: QueuedCommand }>(
          `${base}/conversations/${conversationId}/disappearing`,
          { method: 'PUT', body: { timer_seconds: timerSeconds } }
        ),
        addLabel: (conversationId: number, labelId: number) =>
          client<{ data: { label_id: number } }>(`${base}/conversations/${conversationId}/labels/${labelId}`, {
            method: 'PUT'
          }),
        removeLabel: (conversationId: number, labelId: number) =>
          client<unknown>(`${base}/conversations/${conversationId}/labels/${labelId}`, { method: 'DELETE' })
      },
      stickers: {
        list: (
          params: {
            inbox_id: number
            filter: StickerLibraryFilter
            page?: number
            per_page?: number
          },
          options?: { signal?: AbortSignal }
        ) => client<StickerLibraryListResponse>(`${base}/inboxes/${params.inbox_id}/stickers`, {
          query: {
            ...(params.filter === 'favorites' ? { favorite: 'any' } : {}),
            page: params.page,
            per_page: params.per_page
          },
          signal: options?.signal
        }),
        preview: (id: string, options?: { signal?: AbortSignal }) => {
          if (!validStickerId(id)) return Promise.reject(new Error('Figurinha inválida.'))
          return client<Blob>(`${base}/stickers/${id}/preview`, {
            method: 'GET',
            signal: options?.signal,
            responseType: 'blob' as 'json',
            headers: { Accept: 'image/webp' }
          })
        },
        import: (body: { inbox_id: number, file: File }) => {
          const payload = new FormData()
          payload.set('file', body.file, body.file.name)
          return client<{ data: StickerLibraryItem }>(`${base}/inboxes/${body.inbox_id}/stickers/import`, {
            method: 'POST',
            body: payload
          })
        },
        favorite: (id: string, favorite: boolean) => {
          if (!validStickerId(id)) return Promise.reject(new Error('Figurinha inválida.'))
          return client<{ data: StickerLibraryItem }>(`${base}/stickers/${id}/favorite`, {
            method: 'PUT',
            body: { favorite }
          })
        },
        remove: (id: string) => {
          if (!validStickerId(id)) return Promise.reject(new Error('Figurinha inválida.'))
          return client<unknown>(`${base}/stickers/${id}`, { method: 'DELETE' })
        }
      },
      catalog: {
        fetchGifAsset: (assetPath: string) => {
          if (!/^\/api\/v1\/communication\/gifs\/[A-Za-z0-9]{40}\/asset$/.test(assetPath)) {
            return Promise.reject(new Error('Arquivo de GIF inválido.'))
          }
          return client<Blob>(assetPath, {
            method: 'GET',
            // Sanctum client types responseType as json; blob is supported at runtime.
            responseType: 'blob' as 'json',
            headers: { Accept: 'video/mp4,video/webm' }
          })
        },
        searchGifs: (params: { inbox_id: number, q: string, limit?: number }) => client<{
          data: Array<{ id: string, title: string, preview_path: string, asset_path: string, asset_token: string }>
        }>(`${base}/gifs/search`, { query: params }),
        outboundCapabilities: (params?: { inbox_id?: number }) => client<{
          data: OutboundCapabilities
        }>(`${base}/outbound-capabilities`, {
          query: params?.inbox_id ? { inbox_id: params.inbox_id } : undefined,
          timeout: 10_000
        }),
        labels: () => client<{ data: Label[] }>(`${base}/labels`),
        createLabel: (body: { name: string, color?: string }) =>
          client<{ data: Label }>(`${base}/labels`, { method: 'POST', body }),
        deleteLabel: (id: number) => client<unknown>(`${base}/labels/${id}`, { method: 'DELETE' }),
        /** Listagem de uso no composer (somente ativos; sem meta se sem page/per_page). */
        cannedResponses: (params?: { q?: string }) =>
          client<{ data: CannedResponse[] }>(`${base}/canned-responses`, {
            query: params?.q ? { q: params.q } : undefined
          }),
        /** Listagem de gestão (ativos/inativos, paginação). Exige manage_quick_replies. */
        listCannedResponses: (params?: CannedResponseListParams) =>
          client<{
            data: CannedResponse[]
            meta: { current_page: number, last_page: number, total: number }
          }>(`${base}/canned-responses`, {
            query: { manage: 1, ...params }
          }),
        createCannedResponse: (body: CannedResponseWriteBody) =>
          client<{ data: CannedResponse }>(`${base}/canned-responses`, { method: 'POST', body }),
        updateCannedResponse: (id: number, body: CannedResponseWriteBody & { lock_version: number }) =>
          client<{ data: CannedResponse }>(`${base}/canned-responses/${id}`, { method: 'PUT', body }),
        duplicateCannedResponse: (id: number, body: { shortcut: string }) =>
          client<{ data: CannedResponse }>(`${base}/canned-responses/${id}/duplicate`, {
            method: 'POST',
            body
          }),
        deactivateCannedResponse: (id: number) =>
          client<{ data: CannedResponse }>(`${base}/canned-responses/${id}/deactivate`, {
            method: 'POST'
          }),
        renderCannedResponse: (id: number, body: { conversation_id: number }) =>
          client<{ data: CannedRenderResult }>(`${base}/canned-responses/${id}/render`, {
            method: 'POST',
            body
          }),
        deleteCannedResponse: (id: number) => client<unknown>(`${base}/canned-responses/${id}`, { method: 'DELETE' })
      },
      flows: {
        list: () => client<{ data: Flow[], meta: FlowListMeta }>(`${base}/flows`),
        create: (body: { name: string }) =>
          client<{ data: Flow }>(`${base}/flows`, { method: 'POST', body }),
        get: (id: number) =>
          client<{ data: Flow }>(`${base}/flows/${id}`),
        update: (id: number, body: {
          name?: string
          status?: FlowStatus
          lock_version: number
        }) => client<{ data: Flow }>(`${base}/flows/${id}`, { method: 'PATCH', body }),
        remove: (id: number) =>
          client<unknown>(`${base}/flows/${id}`, { method: 'DELETE' }),
        getDraft: (id: number) =>
          client<{ data: FlowDraft }>(`${base}/flows/${id}/draft`),
        updateDraft: (id: number, body: { graph: FlowGraph, lock_version: number }) =>
          client<{ data: FlowDraft }>(`${base}/flows/${id}/draft`, {
            method: 'PUT',
            body
          }),
        validate: (id: number, body?: { graph?: FlowGraph }) =>
          client<{ data: FlowValidateResult }>(`${base}/flows/${id}/validate`, {
            method: 'POST',
            body: body ?? {}
          }),
        dryRun: (id: number, body?: {
          graph?: FlowGraph
          context?: FlowDryRunContext
        }) => client<{ data: FlowDryRunResult }>(`${base}/flows/${id}/dry-run`, {
          method: 'POST',
          body: body ?? {}
        }),
        preview: (id: number, body?: { graph?: FlowGraph }) =>
          client<{ data: FlowPreviewResult }>(`${base}/flows/${id}/preview`, {
            method: 'POST',
            body: body ?? {}
          }),
        publish: (id: number, body: { lock_version: number }) =>
          client<{ data: FlowPublishResult }>(`${base}/flows/${id}/publish`, {
            method: 'POST',
            body
          }),
        clone: (id: number, body: { name: string, from_version_id?: number | null }) =>
          client<{ data: Flow }>(`${base}/flows/${id}/clone`, {
            method: 'POST',
            body
          }),
        cloneVersion: (id: number, versionId: number, body?: { name?: string }) =>
          client<{ data: Flow }>(`${base}/flows/${id}/versions/${versionId}/clone`, {
            method: 'POST',
            body: body ?? {}
          }),
        listBindings: (id: number) =>
          client<{ data: FlowBinding[] }>(`${base}/flows/${id}/bindings`),
        createBinding: (id: number, body: {
          inbox_id: number
          published_version_id?: number | null
          enabled?: boolean
        }) => client<{ data: FlowBinding }>(`${base}/flows/${id}/bindings`, {
          method: 'POST',
          body
        }),
        updateBinding: (bindingId: number, body: {
          published_version_id?: number | null
          enabled?: boolean
          lock_version: number
        }) => client<{ data: FlowBinding }>(`${base}/flow-bindings/${bindingId}`, {
          method: 'PATCH',
          body
        }),
        enableBinding: (bindingId: number, body: {
          lock_version: number
          published_version_id?: number | null
        }) => client<{ data: FlowBinding }>(
          `${base}/flow-bindings/${bindingId}/enable`,
          { method: 'POST', body }
        ),
        disableBinding: (bindingId: number, body: { lock_version: number }) =>
          client<{ data: FlowBinding }>(
            `${base}/flow-bindings/${bindingId}/disable`,
            { method: 'POST', body }
          ),
        removeBinding: (bindingId: number) =>
          client<unknown>(`${base}/flow-bindings/${bindingId}`, { method: 'DELETE' }),
        pauseRun: (runId: number) =>
          client<{ data: FlowRun }>(`${base}/flow-runs/${runId}/pause`, { method: 'POST' }),
        resumeRun: (runId: number) =>
          client<{ data: FlowRun }>(`${base}/flow-runs/${runId}/resume`, { method: 'POST' }),
        handoffRun: (runId: number) =>
          client<{ data: FlowRun }>(`${base}/flow-runs/${runId}/handoff`, { method: 'POST' }),
        stopRun: (runId: number) =>
          client<{ data: FlowRun }>(`${base}/flow-runs/${runId}/stop`, { method: 'POST' }),
        restartRun: (runId: number) =>
          client<{ data: FlowRun }>(`${base}/flow-runs/${runId}/restart`, { method: 'POST' }),
        listRuns: (params?: {
          flow_id?: number
          status?: string
          active_only?: boolean
          page?: number
          per_page?: number
        }) => client<{
          data: FlowRun[]
          meta: { current_page: number, last_page: number, total: number }
        }>(`${base}/flow-runs`, { query: params }),
        getRun: (runId: number) =>
          client<{ data: FlowRun }>(`${base}/flow-runs/${runId}`)
      },
      events: {
        sync: (after: number, limit = 200) =>
          client<{ data: Event[], meta: SyncMeta }>(`${base}/events`, {
            query: { after, limit }
          })
      },
      attachments: {
        downloadUrl: (id: number) => apiUrl(`${base}/attachments/${id}/download`)
      },
      automation: {
        list: () => client<{ data: AutomationPolicy[], meta: AutomationMeta }>(
          `${base}/automation-policies`
        ),
        upsert: (body: PolicyBody) =>
          client<{ data: AutomationPolicy }>(`${base}/automation-policies`, {
            method: 'PUT',
            body
          }),
        recipients: (clientId: number, moduleKey: string, submoduleKey: string) =>
          client<{ data: RecipientConfiguration }>(
            `${base}/clients/${clientId}/automation-recipients`,
            { query: { module_key: moduleKey, submodule_key: submoduleKey } }
          ),
        updateRecipients: (clientId: number, body: {
          module_key: string
          submodule_key: string
          recipient_mode: RecipientMode
          identity_ids: number[]
          lock_version: number
        }) => client<{ data: RecipientConfiguration }>(
          `${base}/clients/${clientId}/automation-recipients`,
          { method: 'PUT', body }
        )
      }
    }
  }
}
