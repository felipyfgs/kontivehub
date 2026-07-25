/**
 * Chat do assistente via JSON (MVP) — não usa `useChat` do AI SDK com stream.
 *
 * Motivo: o backend Laravel conclui o turno antes de responder (`format: "json"`)
 * e a mutação `create_process_template` exige POST separado em `/approve-tool`.
 */
import type {
  AssistantPendingApproval,
  AssistantUiMessage
} from '~/types/assistant'

export type AssistantChatStatus = 'ready' | 'submitted' | 'error'

function toUiMessage(id: string | number, role: string, content: string | null | undefined): AssistantUiMessage | null {
  if (role !== 'user' && role !== 'assistant' && role !== 'system') {
    return null
  }
  const text = typeof content === 'string' ? content : ''
  if (!text && role === 'assistant') {
    return null
  }
  return {
    id: String(id),
    role,
    parts: [{ type: 'text', text }]
  }
}

function previewApprovalArgs(args: Record<string, unknown>): string {
  try {
    return JSON.stringify(args, null, 2)
  } catch {
    return String(args)
  }
}

export function useAssistantChat() {
  const api = useApi()
  const toast = useToast()

  const conversationId = ref<number | null>(null)
  const messages = ref<AssistantUiMessage[]>([])
  const pendingApprovals = ref<AssistantPendingApproval[]>([])
  const status = ref<AssistantChatStatus>('ready')
  const errorMessage = ref<string | null>(null)
  const approvingToken = ref<string | null>(null)
  const bootstrapping = ref(false)

  function reset() {
    conversationId.value = null
    messages.value = []
    pendingApprovals.value = []
    status.value = 'ready'
    errorMessage.value = null
    approvingToken.value = null
    bootstrapping.value = false
  }

  function replaceMessagesFromApi(
    apiMessages: Array<{ id: number, role: string, content: string | null }>
  ) {
    messages.value = apiMessages
      .map(m => toUiMessage(m.id, m.role, m.content))
      .filter((m): m is AssistantUiMessage => m !== null)
  }

  async function ensureConversation(): Promise<number | null> {
    if (conversationId.value != null) {
      return conversationId.value
    }

    bootstrapping.value = true
    errorMessage.value = null
    try {
      const created = await api.assistant.conversations.create()
      conversationId.value = created.data.id
      return created.data.id
    } catch (error) {
      errorMessage.value = apiErrorMessage(
        error,
        'Não foi possível iniciar a conversa do assistente.'
      )
      status.value = 'error'
      return null
    } finally {
      bootstrapping.value = false
    }
  }

  async function bootstrapOnOpen() {
    if (conversationId.value != null) {
      return
    }
    const id = await ensureConversation()
    if (id == null) {
      return
    }
    try {
      const history = await api.assistant.conversations.messages(id)
      replaceMessagesFromApi(history.data)
    } catch {
      // Conversa nova ou histórico indisponível — UI vazia é ok.
    }
  }

  async function sendMessage(text: string) {
    const trimmed = text.trim()
    if (!trimmed || status.value === 'submitted') {
      return
    }

    const id = await ensureConversation()
    if (id == null) {
      return
    }

    const optimisticId = `local-user-${Date.now()}`
    messages.value = [
      ...messages.value,
      {
        id: optimisticId,
        role: 'user',
        parts: [{ type: 'text', text: trimmed }]
      }
    ]
    pendingApprovals.value = []
    status.value = 'submitted'
    errorMessage.value = null

    try {
      const turn = await api.assistant.conversations.chat(id, {
        message: trimmed,
        format: 'json'
      })

      if (turn.data.messages?.length) {
        replaceMessagesFromApi(turn.data.messages)
      } else {
        const assistantText = turn.data.assistant_text || ''
        messages.value = [
          ...messages.value.filter(m => m.id !== optimisticId),
          {
            id: `local-user-${Date.now()}-final`,
            role: 'user',
            parts: [{ type: 'text', text: trimmed }]
          },
          ...(assistantText
            ? [{
                id: `local-assistant-${Date.now()}`,
                role: 'assistant' as const,
                parts: [{ type: 'text' as const, text: assistantText }]
              }]
            : [])
        ]
      }

      pendingApprovals.value = (turn.data.pending_approvals || []).filter(
        a => a.tool_name === 'create_process_template'
      )
      status.value = 'ready'
    } catch (error) {
      errorMessage.value = apiErrorMessage(
        error,
        'Falha ao enviar mensagem ao assistente.'
      )
      status.value = 'error'
      messages.value = messages.value.filter(m => m.id !== optimisticId)
    }
  }

  async function approvePending(approval: AssistantPendingApproval) {
    const id = conversationId.value
    if (id == null || approvingToken.value) {
      return
    }

    approvingToken.value = approval.approval_token
    errorMessage.value = null

    try {
      const result = await api.assistant.conversations.approveTool(id, {
        approval_token: approval.approval_token
      })

      pendingApprovals.value = pendingApprovals.value.filter(
        a => a.approval_token !== approval.approval_token
      )

      const templateName = result.data.result?.name
      toast.add({
        title: templateName
          ? `Rotina “${templateName}” criada`
          : 'Rotina criada',
        description: 'Você pode revisar em Work → Rotinas.',
        color: 'success',
        actions: [{
          label: 'Abrir rotinas',
          onClick: () => {
            void navigateTo('/work/templates')
          }
        }]
      })

      if (result.data.message?.content) {
        messages.value = [
          ...messages.value,
          {
            id: `approve-${result.data.message.id ?? Date.now()}`,
            role: 'assistant',
            parts: [{ type: 'text', text: result.data.message.content }]
          }
        ]
      }
    } catch (error) {
      errorMessage.value = apiErrorMessage(
        error,
        'Não foi possível aprovar a criação da rotina.'
      )
      toast.add({
        title: errorMessage.value,
        color: 'error'
      })
    } finally {
      approvingToken.value = null
    }
  }

  async function denyPending(approval: AssistantPendingApproval) {
    const id = conversationId.value
    pendingApprovals.value = pendingApprovals.value.filter(
      a => a.approval_token !== approval.approval_token
    )

    if (id != null) {
      try {
        await api.assistant.conversations.denyTool(id, {
          approval_token: approval.approval_token
        })
      } catch {
        // UI já removeu o pending; token pode já ter expirado.
      }
    }

    toast.add({
      title: 'Criação cancelada',
      description: 'A rotina não foi criada.',
      color: 'neutral'
    })
  }

  return {
    conversationId,
    messages,
    pendingApprovals,
    status,
    errorMessage,
    approvingToken,
    bootstrapping,
    previewApprovalArgs,
    reset,
    bootstrapOnOpen,
    sendMessage,
    approvePending,
    denyPending
  }
}
