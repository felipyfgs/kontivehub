import type { PageMeta } from '~/types/api'
import type {
  AssistantApproveToolResult,
  AssistantChatTurn,
  AssistantConversation,
  AssistantMessage
} from '~/types/assistant'
import type { ApiClient } from './types'

export function createAssistantApi(client: ApiClient) {
  const base = '/api/v1/assistant'

  return {
    assistant: {
      conversations: {
        list: (params?: { page?: number, per_page?: number }) =>
          client<{ data: AssistantConversation[], meta: PageMeta }>(`${base}/conversations`, {
            query: params
          }),
        create: (body?: { title?: string }) =>
          client<{ data: AssistantConversation }>(`${base}/conversations`, {
            method: 'POST',
            body: body || {}
          }),
        messages: (conversationId: number) =>
          client<{ data: AssistantMessage[] }>(
            `${base}/conversations/${conversationId}/messages`
          ),
        chat: (conversationId: number, body: { message: string, format?: 'json' }) =>
          client<{ data: AssistantChatTurn }>(
            `${base}/conversations/${conversationId}/chat`,
            {
              method: 'POST',
              body: {
                message: body.message,
                format: body.format ?? 'json'
              }
            }
          ),
        approveTool: (conversationId: number, body: { approval_token: string }) =>
          client<{ data: AssistantApproveToolResult }>(
            `${base}/conversations/${conversationId}/approve-tool`,
            {
              method: 'POST',
              body
            }
          ),
        denyTool: (conversationId: number, body: { approval_token: string }) =>
          client<{ data: { status: string } }>(
            `${base}/conversations/${conversationId}/deny-tool`,
            {
              method: 'POST',
              body
            }
          )
      }
    }
  }
}
