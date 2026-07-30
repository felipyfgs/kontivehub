## MODIFIED Requirements

### Requirement: Workspace mantém master–detail e estados reais
A SPA SHALL preservar painel redimensionável, timeline adjacente, contexto largo e `USlideover` mobile, além de deep-link, URL↔seleção, setas, scroll, restauração de foco e `Esc`.

A lista SHALL mostrar nome resolvido, contexto secundário, preview, horário e contador discreto; apenas linhas não lidas usam tipografia destacada. Avatar SHALL usar `conversation.contact.profile_picture_url` same-origin quando `profile_picture_state=READY` e iniciais locais nos demais estados, sem fetch direto ao gateway/provider. Controles de seleção MUST NOT substituir nem cobrir integralmente o avatar sob hover, foco, seleção ou ponteiro coarse. Atualização realtime de estado/versão SHALL renovar lista, timeline e contexto sem reload manual.

#### Scenario: Estados de lista
- **WHEN** a listagem está carregando, falha, fica vazia ou tem próxima página
- **THEN** o workspace mostra o estado real correspondente sem dados sintéticos

#### Scenario: Conversa pinada no filtro unread
- **WHEN** a conversa selecionada fica lida sob o filtro “Não lidas”
- **THEN** ela permanece pinada até fechar ou trocar de seleção

#### Scenario: Foto está pronta
- **WHEN** a API devolve `profile_picture_state=READY` e `profile_picture_url` para uma conversation
- **THEN** lista, navbar/timeline e contexto usam a mesma foto circular autorizada

#### Scenario: Conversa é focada ou selecionada
- **WHEN** a linha recebe foco, abre a conversa ou exibe o controle de seleção em desktop/mobile
- **THEN** a foto continua visível e o checkbox ocupa somente uma área menor e independente do avatar

#### Scenario: Foto fica pronta em realtime
- **WHEN** um refresh assíncrono promove nova versão enquanto o workspace está aberto
- **THEN** as superfícies afetadas passam a usar a URL nova sem consultar Wazync/CDN nem perder seleção

#### Scenario: Foto está indisponível
- **WHEN** o estado não é `READY`, a URL é nula ou a imagem responde com erro
- **THEN** todas as superfícies usam as iniciais locais sem chamar Wazync nem inventar imagem
