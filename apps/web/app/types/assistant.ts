export interface AssistantConversation {
  id: number
  title: string | null
  created_at: string | null
  updated_at: string | null
}

export interface AssistantMessage {
  id: number
  role: string
  content: string | null
  tool_calls: unknown
  tool_results: unknown
  created_at: string | null
}

export interface AssistantPendingApproval {
  approval_token: string
  tool_name: string
  tool_call_id: string
  args: Record<string, unknown>
}

export interface AssistantChatTurn {
  assistant_text: string | null
  pending_approvals: AssistantPendingApproval[]
  messages: AssistantMessage[]
}

export interface AssistantApproveToolResult {
  status: string
  result?: { id?: number, name?: string } | null
  error?: string | null
  message?: { id?: number, role?: string, content?: string | null, created_at?: string | null } | null
}

export interface AssistantUiMessage {
  id: string
  role: 'user' | 'assistant' | 'system'
  parts: Array<{ type: 'text', text: string }>
}
