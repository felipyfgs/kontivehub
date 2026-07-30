## MODIFIED Requirements

### Requirement: Workspace mantém master–detail e estados reais
A SPA SHALL preservar painel redimensionável, timeline adjacente, contexto largo e `USlideover` mobile, além de deep-link, URL↔seleção, setas, scroll, restauração de foco e `Esc`.

A lista SHALL mostrar nome resolvido, contexto secundário, preview, horário e contador discreto; apenas linhas não lidas usam tipografia destacada. Avatar SHALL usar `conversation.contact.profile_picture_url` same-origin quando disponível e iniciais locais quando o campo estiver ausente, nulo ou falhar, sem fetch direto ao gateway/provider. A lista virtualizada SHALL manter CSS e cálculo de offsets derivados da mesma altura em unidade responsiva à fonte, sem truncar controles ou texto sob preferência ampliada. Toda intenção de restauração de foco SHALL ser consumida uma vez, exista ou não o alvo após filtro/rota.

#### Scenario: Estados de lista
- **WHEN** a listagem está carregando, falha, fica vazia ou tem próxima página
- **THEN** o workspace mostra o estado real correspondente sem dados sintéticos

#### Scenario: Conversa pinada no filtro unread
- **WHEN** a conversa selecionada fica lida sob o filtro “Não lidas”
- **THEN** ela permanece pinada até fechar ou trocar de seleção

#### Scenario: Foto está pronta
- **WHEN** a API devolve `profile_picture_url` para uma conversation
- **THEN** lista, navbar/timeline e contexto usam a mesma foto circular autorizada

#### Scenario: Foto está indisponível
- **WHEN** o campo é nulo, ausente ou a imagem responde com erro
- **THEN** todas as superfícies usam as iniciais locais sem chamar Wazync nem inventar imagem

#### Scenario: Fonte é ampliada
- **WHEN** o operador usa preferência de fonte maior ou zoom de texto
- **THEN** a altura medida, os offsets, a materialização e o foco continuam alinhados sem sobrepor linhas

#### Scenario: Alvo de foco saiu da lista
- **WHEN** filtro, rota ou refresh remove a conversa antes da restauração
- **THEN** a tentativa termina sem prender uma intenção que possa focar um item futuro indevido
