/**
 * Glossário canônico de labels do módulo Trabalho (C2).
 * Paths técnicos (`/work/templates`, `ProcessTemplate`) podem permanecer.
 */
export const WORK_ROUTINES_GLOSSARY = {
  rotina: {
    singular: 'Rotina',
    plural: 'Rotinas',
    description: 'Definição reutilizável configurável pelo Escritório (agenda, tarefas, público).'
  },
  catalogo: {
    singular: 'Catálogo',
    plural: 'Catálogo',
    description: 'Biblioteca de padrões instaláveis; a cópia resultante é uma Rotina do Escritório.'
  },
  processo: {
    singular: 'Processo',
    plural: 'Processos',
    description: 'Instância Cliente fiscal + Período; superfície de coordenação.'
  },
  tarefa: {
    singular: 'Tarefa',
    plural: 'Tarefas',
    description: 'Unidade de execução; superfície transversal de fila/lista/kanban.'
  },
  coordenador: {
    singular: 'Coordenador',
    plural: 'Coordenadores',
    description: 'Responsável pelo Processo (coordenação).'
  },
  executor: {
    singular: 'Executor',
    plural: 'Executores',
    description: 'Responsável pela Tarefa (execução).'
  },
  /** Termo técnico legado — não usar como rótulo principal na UI. */
  modeloLegacyForbiddenAsPrimaryLabel: 'Modelo'
} as const

/** Superfícies Work onde "Modelo/Modelos" deve ser substituído por Rotina/Rotinas. */
export const WORK_ROUTINES_COPY_SURFACES = [
  'app/utils/work-navigation.ts',
  'app/pages/work/templates/index.vue',
  'app/pages/work/processes/index.vue',
  'app/pages/work/processes/[id].vue',
  'app/components/work/WorkQueueWorkspace.vue',
  'app/composables/useAssistantChat.ts'
] as const
