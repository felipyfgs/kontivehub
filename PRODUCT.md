# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

O usuário principal é o operador de escritório contábil responsável pela
carteira fiscal e pelo trabalho operacional diário dos clientes do escritório.

Administradores do escritório configuram o tenant, a equipe, permissões,
módulos, credenciais e capacidades de maior risco. Administradores da
plataforma operam a instalação global e entram em contexto de tenant somente
por mecanismos privilegiados explícitos e auditados.

## Product Purpose

KontiveHub centraliza a operação de escritórios contábeis para que a equipe
enxergue o que exige atenção, consulte evidências confiáveis e execute o
trabalho relacionado a cada cliente sem depender de controles fragmentados.
Sucesso significa transformar dados fiscais, documentos, tarefas e conversas
em fluxos operacionais claros, seguros e rastreáveis.

## Positioning

KontiveHub reúne clientes, monitoramento fiscal, documentos, trabalho e
atendimento em fluxos multi-tenant seguros e auditáveis. A combinação reduz a
fragmentação entre portais oficiais, caixas de entrada e controles manuais sem
ocultar a origem, o risco ou o estado real de cada operação.

## Operating Context

- O escritório trabalha dentro de um `Tenant`; usuários acessam apenas tenants
  para os quais possuem membership válida.
- A unidade cotidiana de operação é a carteira de clientes e estabelecimentos,
  com documentos, obrigações, pendências, prazos, credenciais e evidências.
- A equipe consulta e acompanha integrações brasileiras como SERPRO/Integra
  Contador, SEFAZ, ADN, FGTS Digital, eSocial e serviços de MEI.
- O trabalho é organizado em cockpit, filas, processos, tarefas, rotinas,
  calendário e indicadores operacionais.
- O atendimento reúne conversas, contatos, respostas rápidas e fluxos; o
  WhatsApp é transportado por um gateway técnico interno, enquanto as regras de
  negócio permanecem no KontiveHub.
- A interface é utilizada em pt-BR como SPA/PWA no navegador, em desktop e
  dispositivos móveis.

## Capabilities and Constraints

- Gestão de identidade, onboarding, tenants, memberships, papéis e permissões
  granulares.
- Cadastro e acompanhamento de clientes, estabelecimentos, categorias e
  credenciais.
- Catálogo documental, importações, exportações, sincronizações e recuperação
  de documentos fiscais.
- Monitoramento fiscal, caixa postal, declarações, parcelamentos, situação
  fiscal, cadastros, procurações e operações governadas por integrações
  oficiais.
- Trabalho operacional (`Work`) e comunicação de negócio são domínios
  independentes das integrações fiscais, embora apareçam juntos para o
  operador.
- A API Laravel é a autoridade de domínio, dados, autorização e políticas; a
  SPA Nuxt apenas apresenta seus fluxos e não acessa gateways ou bancos
  diretamente.
- Isolamento tenant, autorização e acesso a dados são fail-closed. O produto
  não confia em `tenant_id` arbitrário recebido pela interface.
- Mutações fiscais, egress real, automações e rollouts exigem gates explícitos,
  idempotência, auditoria e condições seguras para retry.
- A interface não inventa dados ou estados sintéticos quando a API falha.
- Dados fiscais, credenciais, conteúdo de mensagens e identificadores sensíveis
  não podem aparecer em logs, métricas ou superfícies não autorizadas.

## Brand Commitments

- O nome canônico do produto é **KontiveHub**.
- Interface e documentação de produto usam português do Brasil; termos
  oficiais de integrações e identificadores técnicos preservam sua nomenclatura
  própria.
- A voz é direta, operacional e precisa. Ela distingue dado confirmado,
  pendência, risco, bloqueio e ação disponível sem prometer resultados que o
  sistema não verificou.

## Evidence on Hand

- Contexto canônico do produto e dos boundaries em `AGENTS.md` e
  `openspec/config.yaml`.
- Contrato público da SPA em
  `apps/api/resources/contracts/public.openapi.json` e contrato interno do
  gateway em `apps/api/resources/contracts/wazync.openapi.yaml`.
- Navegação, fluxos e linguagem reais em `apps/web/app`, com inventários e
  testes de jornadas em `apps/web/tests`.
- A descrição instalada da PWA é “Gestão fiscal, documentos e trabalho para
  escritórios contábeis”.
- Não há evidência confirmada de depoimentos, métricas de clientes, estudos de
  caso, preços ou alegações comerciais públicas. Trabalhos futuros não devem
  fabricá-los.

## Product Principles

1. **Verdade operacional antes de aparência de completude.** Mostrar o estado
   confirmado, preservar a origem da informação e tornar falhas ou bloqueios
   explícitos.
2. **O tenant é uma fronteira de confiança.** Toda leitura, ação e colaboração
   deve respeitar membership, permissão e isolamento entre escritórios.
3. **Centralizar sem apagar responsabilidades.** Oferecer uma experiência
   unificada ao operador mantendo limites claros entre domínio fiscal, trabalho,
   comunicação e transporte técnico.
4. **Ação segura e rastreável.** Operações de risco precisam de confirmação,
   idempotência, auditoria e recuperação previsível.
5. **Trabalho diário primeiro.** Priorizar carteira, atenção, prazos, próximas
   ações e continuidade do fluxo sobre exposição de complexidade técnica.
