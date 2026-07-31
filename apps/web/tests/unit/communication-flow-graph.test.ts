import { describe, expect, it } from 'vitest'
import type { FlowGraph } from '~/types/communication/flows'
import {
  canInsertFlowNodeType,
  connectFlowNodes,
  domainGraphToVueFlow,
  FLOW_NODE_TYPES,
  insertFlowNode,
  isFlowNodeType,
  normalizeFlowGraph,
  validateFlowGraphClient,
  vueFlowToDomainGraph
} from '~/utils/communication-flow-graph'

function sampleGraph(): FlowGraph {
  return {
    nodes: [
      { id: 'start_1', type: 'start', data: {}, position: { x: 0, y: 0 } },
      {
        id: 'msg_1',
        type: 'message',
        data: { body: 'Olá' },
        position: { x: 200, y: 0 }
      },
      { id: 'end_1', type: 'end', data: {}, position: { x: 400, y: 0 } }
    ],
    edges: [
      { id: 'e1', source: 'start_1', target: 'msg_1' },
      { id: 'e2', source: 'msg_1', target: 'end_1' }
    ]
  }
}

describe('communication-flow-graph adapters', () => {
  it('permite somente nós allowlisted', () => {
    expect(FLOW_NODE_TYPES).toEqual([
      'start',
      'message',
      'quick_reply',
      'question',
      'condition',
      'delay',
      'action',
      'handoff',
      'end'
    ])
    expect(isFlowNodeType('message')).toBe(true)
    expect(isFlowNodeType('webhook')).toBe(false)
    expect(canInsertFlowNodeType('ai', sampleGraph()).ok).toBe(false)
  })

  it('rejeita segundo start e round-trip vue-flow', () => {
    expect(canInsertFlowNodeType('start', sampleGraph())).toMatchObject({ ok: false })
    const inserted = insertFlowNode(sampleGraph(), 'handoff', { connectFrom: 'msg_1' })
    expect('error' in inserted).toBe(false)
    if ('error' in inserted) return

    const vf = domainGraphToVueFlow(inserted)
    expect(vf.nodes.every(node => typeof node.id === 'string')).toBe(true)
    const back = vueFlowToDomainGraph(vf.nodes, vf.edges)
    expect(back.nodes.map(node => node.type)).toContain('handoff')
    expect(back.edges.some(edge => edge.source === 'msg_1')).toBe(true)
  })

  it('valida DAG e rejeita ciclo / tipo proibido', () => {
    const ok = validateFlowGraphClient(sampleGraph())
    expect(ok.valid).toBe(true)

    const withCycle = normalizeFlowGraph({
      nodes: sampleGraph().nodes,
      edges: [
        ...sampleGraph().edges,
        { source: 'end_1', target: 'msg_1' }
      ]
    })
    expect(validateFlowGraphClient(withCycle).errors.some(error => error.code === 'cycle_detected')).toBe(true)

    const forbidden = normalizeFlowGraph({
      nodes: [
        { id: 's', type: 'start' },
        { id: 'w', type: 'webhook' }
      ],
      edges: []
    })
    expect(forbidden.nodes.map(node => node.type)).toEqual(['start'])
  })

  it('conecta nós sem drag', () => {
    const connected = connectFlowNodes(sampleGraph(), 'start_1', 'end_1')
    expect('error' in connected).toBe(false)
    if ('error' in connected) return
    expect(connected.edges.some(edge => edge.source === 'start_1' && edge.target === 'end_1')).toBe(true)
  })
})
