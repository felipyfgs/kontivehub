## MODIFIED Requirements

### Requirement: Workspace mantém master–detail e estados reais
A SPA SHALL preservar painel redimensionável, timeline adjacente, contexto largo e `USlideover` mobile, além de deep-link por path, path↔seleção, filtros de sessão, setas, scroll, restauração de foco e `Esc`. Filtros combináveis SHALL permanecer no estado isolado do workspace e SHALL NOT ser copiados para a query ao abrir ou fechar uma conversa.

A lista SHALL mostrar nome resolvido, contexto secundário, preview, horário e contador discreto; apenas linhas não lidas usam tipografia destacada. Avatar SHALL usar `conversation.contact.profile_picture_url` same-origin quando `profile_picture_state=READY` e iniciais locais nos demais estados, sem fetch direto ao gateway/provider. Controles de seleção MUST NOT substituir nem cobrir integralmente o avatar sob hover, foco, seleção ou ponteiro coarse. A lista virtualizada SHALL manter CSS e cálculo de offsets derivados da mesma altura em unidade responsiva à fonte, sem truncar controles ou texto sob preferência ampliada. Toda intenção de restauração de foco SHALL ser consumida uma vez, exista ou não o alvo após filtro/rota. Atualização realtime de estado/versão SHALL renovar lista, timeline e contexto sem reload manual.

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

#### Scenario: Fonte é ampliada
- **WHEN** o operador usa preferência de fonte maior ou zoom de texto
- **THEN** a altura medida, os offsets, a materialização e o foco continuam alinhados sem sobrepor linhas

#### Scenario: Alvo de foco saiu da lista
- **WHEN** filtro, rota ou refresh remove a conversa antes da restauração
- **THEN** a tentativa termina sem prender uma intenção que possa focar um item futuro indevido

#### Scenario: Conversa abre sob filtro transitório
- **WHEN** o operador abre `/communication/conversations/{id}` enquanto “Não atribuídas” está ativo na sessão
- **THEN** a seleção, o filtro e a lista permanecem coerentes e a URL não recebe `unassigned` nem outra query
