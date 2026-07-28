import type {
  CommunicationAutomationMeta,
  CommunicationAutomationPolicy,
  CommunicationCannedRenderResult,
  CommunicationCannedResponse,
  CommunicationCannedResponseListParams,
  CommunicationCannedResponseWriteBody,
  CommunicationContact,
  CommunicationContactListParams,
  CommunicationConversation,
  CommunicationConversationListMeta,
  CommunicationConversationStatus,
  CommunicationEvent,
  CommunicationFeatureMeta,
  CommunicationFlow,
  CommunicationFlowBinding,
  CommunicationFlowDraft,
  CommunicationFlowDryRunContext,
  CommunicationFlowDryRunResult,
  CommunicationFlowGraph,
  CommunicationFlowListMeta,
  CommunicationFlowPreviewResult,
  CommunicationFlowPublishResult,
  CommunicationFlowRun,
  CommunicationFlowStatus,
  CommunicationFlowValidateResult,
  CommunicationInbox,
  CommunicationLabel,
  CommunicationMessage,
  CommunicationPairingState,
  CommunicationQueuedCommand,
  CommunicationRecipientConfiguration,
  CommunicationRecipientMode,
  CommunicationSessionStatus,
  CommunicationSendKind,
  CommunicationSyncMeta
} from '~/types/communication'
import type { ApiClient, ApiUrl } from './types'

export interface CommunicationConversationFilters {
  q?: string
  inbox_id?: number
  status?: CommunicationConversationStatus
  assignee_membership_id?: number
  work_department_id?: number
  unassigned?: boolean
  unread?: boolean
  page?: number
  per_page?: number
}

export interface CommunicationPolicyBody {
  module_key: string
  submodule_key: string
  inbox_id: number | null
  is_enabled: boolean
  send_day: number
  send_time: string
  timezone: string
  recipient_mode: CommunicationRecipientMode
  template_key: string
  template_version: string
  lock_version: number
}

export function createCommunicationApi(client: ApiClient, apiUrl: ApiUrl) {
  const base = '/api/v1/communication'

  return {
    communication: {
      inboxes: {
        list: () => client<{ data: CommunicationInbox[], meta: CommunicationFeatureMeta }>(`${base}/inboxes`),
        create: (body: {
          name: string
          is_enabled?: boolean
          is_default?: boolean
          work_department_id?: number | null
        }) => client<{ data: CommunicationInbox }>(`${base}/inboxes`, { method: 'POST', body }),
        update: (id: number, body: Partial<Pick<CommunicationInbox,
          'name' | 'is_enabled' | 'is_default' | 'work_department_id'>> & { lock_version: number }) =>
          client<{ data: CommunicationInbox }>(`${base}/inboxes/${id}`, { method: 'PATCH', body }),
        remove: (id: number) =>
          client<{ data: CommunicationQueuedCommand & { deleted: boolean } }>(`${base}/inboxes/${id}`, {
            method: 'DELETE'
          }),
        replaceMembers: (id: number, membershipIds: number[]) =>
          client<{ data: { membership_ids: number[] } }>(`${base}/inboxes/${id}/members`, {
            method: 'PUT',
            body: { membership_ids: membershipIds }
          }),
        connect: (id: number) =>
          client<{ data: CommunicationPairingState }>(`${base}/inboxes/${id}/session/connect`, {
            method: 'POST'
          }),
        disconnect: (id: number) =>
          client<{ data: CommunicationQueuedCommand }>(`${base}/inboxes/${id}/session/disconnect`, {
            method: 'POST'
          }),
        logout: (id: number) =>
          client<{ data: CommunicationQueuedCommand }>(`${base}/inboxes/${id}/session/logout`, {
            method: 'POST'
          }),
        sessionStatus: (id: number) =>
          client<{ data: CommunicationSessionStatus }>(`${base}/inboxes/${id}/session/status`),
        updateTenantSettings: (enabled: boolean) =>
          client<{ data: { enabled: boolean } }>(`${base}/settings`, {
            method: 'PATCH',
            body: { enabled }
          })
      },
      contacts: {
        list: (params?: CommunicationContactListParams) =>
          client<{ data: CommunicationContact[], meta: { current_page: number, last_page: number, total: number } }>(
            `${base}/contacts`,
            { query: params }
          ),
        get: (id: number) => client<{ data: CommunicationContact }>(`${base}/contacts/${id}`),
        create: (body: {
          name?: string | null
          phone: string
          client_id?: number
          client_contact_id?: number
          is_primary?: boolean
          receives_automatic?: boolean
        }) => client<{ data: CommunicationContact }>(`${base}/contacts`, { method: 'POST', body }),
        update: (id: number, body: { name?: string | null, is_active?: boolean }) =>
          client<{ data: CommunicationContact }>(`${base}/contacts/${id}`, { method: 'PATCH', body }),
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
        } }>(`${base}/contacts/${contactId}/personal-data`, { method: 'DELETE' })
      },
      conversations: {
        list: (
          params?: CommunicationConversationFilters,
          options?: { signal?: AbortSignal }
        ) =>
          client<{ data: CommunicationConversation[], meta: CommunicationConversationListMeta }>(
            `${base}/conversations`,
            { query: params, signal: options?.signal }
          ),
        get: (id: number) => client<{ data: CommunicationConversation }>(`${base}/conversations/${id}`),
        messages: (id: number, params?: {
          limit?: number
          before?: string
          after?: string
          anchor?: 'first_unread'
        }) =>
          client<{ data: CommunicationMessage[], meta: {
            next_before?: string | null
            next_after?: string | null
            first_unread_message_id?: number | null
            unread_count?: number
            limit?: number
          } }>(`${base}/conversations/${id}/messages`, { query: params }),
        updateReadState: (id: number, body:
          | { state: 'READ', through_message_id: number }
          | { state: 'UNREAD', expected_version: number }
        ) =>
          client<{ data: CommunicationConversation }>(`${base}/conversations/${id}/read-state`, {
            method: 'PUT',
            body
          }),
        markRead: (id: number, body: { through_message_id: number }) =>
          client<{ data: CommunicationConversation }>(`${base}/conversations/${id}/read-state`, {
            method: 'PUT',
            body: { state: 'READ', through_message_id: body.through_message_id }
          }),
        markUnread: (id: number, body: { expected_version: number }) =>
          client<{ data: CommunicationConversation }>(`${base}/conversations/${id}/read-state`, {
            method: 'PUT',
            body: { state: 'UNREAD', expected_version: body.expected_version }
          }),
        update: (id: number, body: {
          lock_version: number
          status?: CommunicationConversationStatus
          assignee_membership_id?: number | null
          work_department_id?: number | null
          priority?: number
          snoozed_until?: string | null
        }) => client<{ data: CommunicationConversation }>(`${base}/conversations/${id}`, {
          method: 'PATCH',
          body
        }),
        send: (id: number, body: {
          body: string
          internal_note?: boolean
          reply_to_message_id?: number | null
          idempotency_key?: string
          file?: File | null
          kind?: CommunicationSendKind
          ptt?: boolean
        }) => {
          const payload = new FormData()
          payload.set('body', body.body)
          if (body.internal_note) payload.set('internal_note', '1')
          if (body.reply_to_message_id) payload.set('reply_to_message_id', String(body.reply_to_message_id))
          if (body.idempotency_key) payload.set('idempotency_key', body.idempotency_key)
          if (body.kind) payload.set('kind', body.kind)
          if (body.ptt) payload.set('ptt', '1')
          if (body.file) payload.set('file', body.file, body.file.name)
          return client<{ data: CommunicationMessage }>(`${base}/conversations/${id}/messages`, {
            method: 'POST',
            body: payload
          })
        },
        editMessage: (conversationId: number, messageId: number, text: string) =>
          client<{ data: CommunicationQueuedCommand }>(
            `${base}/conversations/${conversationId}/messages/${messageId}/edit`,
            { method: 'PUT', body: { text } }
          ),
        revokeMessage: (conversationId: number, messageId: number) =>
          client<{ data: CommunicationQueuedCommand }>(
            `${base}/conversations/${conversationId}/messages/${messageId}`,
            { method: 'DELETE' }
          ),
        reactMessage: (conversationId: number, messageId: number, emoji: string | null) =>
          client<{ data: CommunicationQueuedCommand }>(
            `${base}/conversations/${conversationId}/messages/${messageId}/reaction`,
            { method: 'PUT', body: { emoji } }
          ),
        votePoll: (conversationId: number, messageId: number, optionNames: string[]) =>
          client<{ data: CommunicationQueuedCommand }>(
            `${base}/conversations/${conversationId}/messages/${messageId}/poll-votes`,
            { method: 'POST', body: { option_names: optionNames } }
          ),
        receipt: (conversationId: number, messageId: number, receipt: 'READ' | 'PLAYED') =>
          client<{ data: CommunicationQueuedCommand }>(
            `${base}/conversations/${conversationId}/messages/${messageId}/receipts`,
            { method: 'POST', body: { receipt } }
          ),
        recoverMessage: (
          conversationId: number,
          messageId: number,
          operation: 'UNAVAILABLE' | 'MEDIA_RETRY'
        ) => client<{ data: CommunicationQueuedCommand }>(
          `${base}/conversations/${conversationId}/messages/${messageId}/recovery`,
          { method: 'POST', body: { operation } }
        ),
        subscribePresence: (conversationId: number) =>
          client<{ data: CommunicationQueuedCommand }>(
            `${base}/conversations/${conversationId}/presence/subscribe`,
            { method: 'POST' }
          ),
        setPresence: (
          conversationId: number,
          presence: 'COMPOSING' | 'PAUSED' | 'RECORDING',
          media?: 'TEXT' | 'AUDIO'
        ) => client<{ data: CommunicationQueuedCommand }>(
          `${base}/conversations/${conversationId}/presence`,
          { method: 'PUT', body: { presence, ...(media ? { media } : {}) } }
        ),
        setDisappearing: (
          conversationId: number,
          timerSeconds: 0 | 86400 | 604800 | 7776000
        ) => client<{ data: CommunicationQueuedCommand }>(
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
      catalog: {
        labels: () => client<{ data: CommunicationLabel[] }>(`${base}/labels`),
        createLabel: (body: { name: string, color?: string }) =>
          client<{ data: CommunicationLabel }>(`${base}/labels`, { method: 'POST', body }),
        deleteLabel: (id: number) => client<unknown>(`${base}/labels/${id}`, { method: 'DELETE' }),
        /** Listagem de uso no composer (somente ativos; sem meta se sem page/per_page). */
        cannedResponses: (params?: { q?: string }) =>
          client<{ data: CommunicationCannedResponse[] }>(`${base}/canned-responses`, {
            query: params?.q ? { q: params.q } : undefined
          }),
        /** Listagem de gestão (ativos/inativos, paginação). Exige manage_quick_replies. */
        listCannedResponses: (params?: CommunicationCannedResponseListParams) =>
          client<{
            data: CommunicationCannedResponse[]
            meta: { current_page: number, last_page: number, total: number }
          }>(`${base}/canned-responses`, {
            query: { manage: 1, ...params }
          }),
        createCannedResponse: (body: CommunicationCannedResponseWriteBody) =>
          client<{ data: CommunicationCannedResponse }>(`${base}/canned-responses`, { method: 'POST', body }),
        updateCannedResponse: (id: number, body: CommunicationCannedResponseWriteBody & { lock_version: number }) =>
          client<{ data: CommunicationCannedResponse }>(`${base}/canned-responses/${id}`, { method: 'PUT', body }),
        duplicateCannedResponse: (id: number, body: { shortcut: string }) =>
          client<{ data: CommunicationCannedResponse }>(`${base}/canned-responses/${id}/duplicate`, {
            method: 'POST',
            body
          }),
        deactivateCannedResponse: (id: number) =>
          client<{ data: CommunicationCannedResponse }>(`${base}/canned-responses/${id}/deactivate`, {
            method: 'POST'
          }),
        renderCannedResponse: (id: number, body: { conversation_id: number }) =>
          client<{ data: CommunicationCannedRenderResult }>(`${base}/canned-responses/${id}/render`, {
            method: 'POST',
            body
          }),
        deleteCannedResponse: (id: number) => client<unknown>(`${base}/canned-responses/${id}`, { method: 'DELETE' })
      },
      flows: {
        list: () => client<{ data: CommunicationFlow[], meta: CommunicationFlowListMeta }>(`${base}/flows`),
        create: (body: { name: string }) =>
          client<{ data: CommunicationFlow }>(`${base}/flows`, { method: 'POST', body }),
        get: (id: number) =>
          client<{ data: CommunicationFlow }>(`${base}/flows/${id}`),
        update: (id: number, body: {
          name?: string
          status?: CommunicationFlowStatus
          lock_version: number
        }) => client<{ data: CommunicationFlow }>(`${base}/flows/${id}`, { method: 'PATCH', body }),
        remove: (id: number) =>
          client<unknown>(`${base}/flows/${id}`, { method: 'DELETE' }),
        getDraft: (id: number) =>
          client<{ data: CommunicationFlowDraft }>(`${base}/flows/${id}/draft`),
        updateDraft: (id: number, body: { graph: CommunicationFlowGraph, lock_version: number }) =>
          client<{ data: CommunicationFlowDraft }>(`${base}/flows/${id}/draft`, {
            method: 'PUT',
            body
          }),
        validate: (id: number, body?: { graph?: CommunicationFlowGraph }) =>
          client<{ data: CommunicationFlowValidateResult }>(`${base}/flows/${id}/validate`, {
            method: 'POST',
            body: body ?? {}
          }),
        dryRun: (id: number, body?: {
          graph?: CommunicationFlowGraph
          context?: CommunicationFlowDryRunContext
        }) => client<{ data: CommunicationFlowDryRunResult }>(`${base}/flows/${id}/dry-run`, {
          method: 'POST',
          body: body ?? {}
        }),
        preview: (id: number, body?: { graph?: CommunicationFlowGraph }) =>
          client<{ data: CommunicationFlowPreviewResult }>(`${base}/flows/${id}/preview`, {
            method: 'POST',
            body: body ?? {}
          }),
        publish: (id: number, body: { lock_version: number }) =>
          client<{ data: CommunicationFlowPublishResult }>(`${base}/flows/${id}/publish`, {
            method: 'POST',
            body
          }),
        clone: (id: number, body: { name: string, from_version_id?: number | null }) =>
          client<{ data: CommunicationFlow }>(`${base}/flows/${id}/clone`, {
            method: 'POST',
            body
          }),
        cloneVersion: (id: number, versionId: number, body?: { name?: string }) =>
          client<{ data: CommunicationFlow }>(`${base}/flows/${id}/versions/${versionId}/clone`, {
            method: 'POST',
            body: body ?? {}
          }),
        listBindings: (id: number) =>
          client<{ data: CommunicationFlowBinding[] }>(`${base}/flows/${id}/bindings`),
        createBinding: (id: number, body: {
          inbox_id: number
          published_version_id?: number | null
          enabled?: boolean
        }) => client<{ data: CommunicationFlowBinding }>(`${base}/flows/${id}/bindings`, {
          method: 'POST',
          body
        }),
        updateBinding: (bindingId: number, body: {
          published_version_id?: number | null
          enabled?: boolean
          lock_version: number
        }) => client<{ data: CommunicationFlowBinding }>(`${base}/flow-bindings/${bindingId}`, {
          method: 'PATCH',
          body
        }),
        enableBinding: (bindingId: number, body: {
          lock_version: number
          published_version_id?: number | null
        }) => client<{ data: CommunicationFlowBinding }>(
          `${base}/flow-bindings/${bindingId}/enable`,
          { method: 'POST', body }
        ),
        disableBinding: (bindingId: number, body: { lock_version: number }) =>
          client<{ data: CommunicationFlowBinding }>(
            `${base}/flow-bindings/${bindingId}/disable`,
            { method: 'POST', body }
          ),
        removeBinding: (bindingId: number) =>
          client<unknown>(`${base}/flow-bindings/${bindingId}`, { method: 'DELETE' }),
        pauseRun: (runId: number) =>
          client<{ data: CommunicationFlowRun }>(`${base}/flow-runs/${runId}/pause`, { method: 'POST' }),
        resumeRun: (runId: number) =>
          client<{ data: CommunicationFlowRun }>(`${base}/flow-runs/${runId}/resume`, { method: 'POST' }),
        handoffRun: (runId: number) =>
          client<{ data: CommunicationFlowRun }>(`${base}/flow-runs/${runId}/handoff`, { method: 'POST' }),
        stopRun: (runId: number) =>
          client<{ data: CommunicationFlowRun }>(`${base}/flow-runs/${runId}/stop`, { method: 'POST' }),
        restartRun: (runId: number) =>
          client<{ data: CommunicationFlowRun }>(`${base}/flow-runs/${runId}/restart`, { method: 'POST' }),
        listRuns: (params?: {
          flow_id?: number
          status?: string
          active_only?: boolean
          page?: number
          per_page?: number
        }) => client<{
          data: CommunicationFlowRun[]
          meta: { current_page: number, last_page: number, total: number }
        }>(`${base}/flow-runs`, { query: params }),
        getRun: (runId: number) =>
          client<{ data: CommunicationFlowRun }>(`${base}/flow-runs/${runId}`)
      },
      events: {
        sync: (after: number, limit = 200) =>
          client<{ data: CommunicationEvent[], meta: CommunicationSyncMeta }>(`${base}/events`, {
            query: { after, limit }
          })
      },
      attachments: {
        downloadUrl: (id: number) => apiUrl(`${base}/attachments/${id}/download`)
      },
      automation: {
        list: () => client<{ data: CommunicationAutomationPolicy[], meta: CommunicationAutomationMeta }>(
          `${base}/automation-policies`
        ),
        upsert: (body: CommunicationPolicyBody) =>
          client<{ data: CommunicationAutomationPolicy }>(`${base}/automation-policies`, {
            method: 'PUT',
            body
          }),
        recipients: (clientId: number, moduleKey: string, submoduleKey: string) =>
          client<{ data: CommunicationRecipientConfiguration }>(
            `${base}/clients/${clientId}/automation-recipients`,
            { query: { module_key: moduleKey, submodule_key: submoduleKey } }
          ),
        updateRecipients: (clientId: number, body: {
          module_key: string
          submodule_key: string
          recipient_mode: CommunicationRecipientMode
          identity_ids: number[]
          lock_version: number
        }) => client<{ data: CommunicationRecipientConfiguration }>(
          `${base}/clients/${clientId}/automation-recipients`,
          { method: 'PUT', body }
        )
      }
    }
  }
}
