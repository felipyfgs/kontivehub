/** Labels pt-BR das colunas da lista de clientes. */
export function clientsColumnLabels(): Record<string, string> {
  return {
    legal_name: 'Razão social / nome',
    credential: 'Certificado digital',
    procuracao: 'Procuração',
    is_active: 'Estado',
    tax_regime: 'Regime tributário',
    actions: 'Ações'
  }
}
