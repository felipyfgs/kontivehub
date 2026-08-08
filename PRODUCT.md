# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- O administrador global da plataforma (`platform_admin`) opera o SaaS, cadastra os escritórios clientes e governa recursos de plataforma. A pessoa atualmente associada a esse papel pode mudar; o papel é a autoridade durável.
- Cada escritório contábil cadastrado é um tenant cliente independente. Seu `tenant_admin` administra o escritório, a equipe, os acessos e as configurações sob sua responsabilidade.
- Usuários do escritório executam o trabalho cotidiano conforme perfis e permissões, incluindo os perfis de sistema `operator` e `viewer`.
- A audiência operacional principal é a equipe do escritório contábil, que trabalha sobre a própria carteira de clientes sem acesso aos dados de outros escritórios.

## Product Purpose

KontiveHub é um SaaS multi-tenant para escritórios contábeis. Centraliza a gestão de clientes, trabalho operacional, documentos, comunicação e monitoramento fiscal para que cada escritório conduza sua operação em um único ambiente, com contexto e dados isolados por tenant.

O produto tem sucesso quando a equipe do escritório consegue acompanhar e executar o trabalho da carteira de clientes com autorização correta, rastreabilidade e segurança, enquanto a administração da plataforma consegue cadastrar e governar os escritórios clientes sem romper seu isolamento.

## Positioning

KontiveHub reúne, no mesmo espaço operacional de cada escritório, clientes, tarefas e processos, documentos, monitoramento fiscal e atendimento por WhatsApp. O mecanismo central é um domínio contábil único no Laravel, servido a uma interface web e integrado a gateways técnicos sem transferir ownership do domínio ou permitir acesso direto entre o frontend e esses gateways.

Cada escritório participa do produto como tenant autônomo do SaaS: identidade, permissões, dados, eventos e ações sensíveis são resolvidos dentro desse contexto e falham de forma fechada quando o contexto ou a autorização não são comprovados.

## Operating Context

- Administração global de escritórios clientes e recursos da plataforma.
- Administração do escritório, departamentos, equipe, assinatura e consumo.
- Cadastro e acompanhamento da carteira de clientes do escritório.
- Planejamento e execução de tarefas, processos, rotinas e calendário operacional.
- Organização, importação, exportação e processamento de documentos fiscais.
- Monitoramento fiscal, sincronizações, saúde das integrações e fechamento.
- Atendimento por WhatsApp, contatos, respostas rápidas e fluxos de comunicação.
- Integrações com fontes e serviços externos fiscais, operadas sob flags, autorização e controles de rollout.
- Uso principal em português do Brasil, por meio de uma aplicação web responsiva.

## Capabilities and Constraints

- Laravel é o dono do domínio, das regras contábeis, da autorização, do tenant atual e dos contratos públicos.
- O Web Nuxt consome a API Laravel e nunca chama o gateway Wazync diretamente.
- Wazync é um gateway técnico de WhatsApp; não contém domínio contábil.
- O isolamento por tenant e a autorização por papel e permissão são invariantes. `platform_admin`, `tenant_admin` e usuários de tenant têm responsabilidades distintas.
- Mudanças de contrato precisam preservar compatibilidade entre Laravel, Nuxt e, quando aplicável, Wazync.
- Integrações externas, egress fiscal e mutações sensíveis permanecem fail-closed e desativados por padrão até rollout explicitamente aprovado.
- PostgreSQL e Redis são serviços de dados compartilhados pela infraestrutura, mas não alteram o limite lógico entre tenants.
- A interface é uma SPA Nuxt 4/Vue 3 com Nuxt UI; a API usa Laravel 13; a comunicação técnica usa Go e whatsmeow.
- A presença de uma rota ou tela não comprova disponibilidade comercial ou prontidão geral do módulo. O status GA, piloto ou experimental de cada módulo continua uma decisão aberta e não deve ser inventado em copy ou marketing.
- Não há padrão formal de conformidade de acessibilidade confirmado. A implementação contém práticas acessíveis, mas trabalhos futuros não devem declarar WCAG ou certificação sem decisão e evidência específicas.

## Brand Commitments

- O nome canônico do produto é **KontiveHub**.
- A linguagem atual do produto é direta, operacional e em português do Brasil, usando terminologia de escritórios contábeis.
- Os ícones PWA e favicons existentes são os únicos assets de marca canônicos identificados no repositório. Não foi encontrada evidência de um logotipo principal separado.
- A frase existente “uso interno” não é um posicionamento durável confirmado: o produto foi definido como SaaS para escritórios clientes e essa copy deve ser reavaliada quando a superfície de autenticação entrar em escopo.

## Evidence on Hand

- `README.md` registra propósito, arquitetura, stack e operação fail-closed.
- `apps/web/app/app.vue` registra nome e descrição canônicos do produto.
- `apps/web/app/utils/navigation.ts` e seus catálogos relacionados demonstram as áreas de trabalho disponíveis na interface.
- `apps/web/app/utils/permissions.ts` e `apps/api/app/Enums/TenantPermission.php` demonstram papéis e autorização granular.
- Testes críticos da API e do Web exercitam isolamento por tenant em identidade, clientes, trabalho, comunicação e monitoramento.
- `apps/web/public/` contém favicon, Apple Touch Icon e ícones PWA; não foi encontrado um logo principal separado.
- Não há, no repositório, evidência aprovada de depoimentos, clientes públicos, benchmarks, preços, certificações, disponibilidade comercial por módulo ou outros claims de marketing. Trabalhos futuros não devem fabricá-los.

## Product Principles

1. **Isolamento antes de conveniência:** nenhuma experiência ou integração pode atravessar o limite do tenant por inferência ou fallback.
2. **Autorização explícita:** toda ação e informação sensível respeita o papel, as permissões e o contexto efetivo do usuário.
3. **Operação contábil unificada:** clientes, trabalho, documentos, comunicação e fiscalidade devem formar um fluxo coerente para o escritório.
4. **Domínio com ownership claro:** Laravel mantém a verdade do negócio; Web e gateways consomem contratos sem duplicar regras contábeis.
5. **Falhar de forma segura e observável:** integrações e mutações sensíveis ficam bloqueadas sem configuração, evidência e rollout explícitos.
