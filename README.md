# KontiveHub

Plataforma de gestão fiscal, documentos, comunicação e trabalho para escritórios contábeis.

O KontiveHub reúne a operação diária do escritório em uma aplicação multiempresa: cadastro de clientes, monitoramento de obrigações fiscais, documentos, guias, processos e tarefas, além de atendimento integrado por WhatsApp. Integrações externas e operações sensíveis são protegidas por feature flags e permanecem desativadas por padrão.

> O projeto está em desenvolvimento ativo. Não habilite integrações reais nem use credenciais de produção sem revisar as flags e os controles de segurança do ambiente.

## Principais recursos

- gestão de escritórios, equipes, departamentos e clientes;
- monitoramento fiscal de declarações, guias, parcelamentos, caixa postal e regularidade;
- importação, catalogação e exportação de documentos fiscais;
- processos, tarefas, calendário, recorrências e evidências de trabalho;
- atendimento e automações de comunicação via WhatsApp;
- integrações fiscais com SERPRO, SEFAZ, ADN, eSocial e portais governamentais;
- auditoria, filas, agendamentos, métricas operacionais e backups.

## Tecnologias

| Camada | Tecnologias principais |
| --- | --- |
| API | PHP 8.4, Laravel 13, Sanctum, Horizon e Reverb |
| Web | Nuxt 4, Vue 3, Nuxt UI, TypeScript e Tailwind CSS |
| Comunicação | Go 1.25 e whatsmeow |
| Dados | PostgreSQL 17 e Redis |
| Infraestrutura | Docker Compose, Docker Swarm, Nginx e Make |
| Testes | PHPUnit, Vitest e Playwright |

## Estrutura do repositório

```text
.
├── apps/
│   ├── api/       # API Laravel, filas, schedules e contratos OpenAPI
│   ├── web/       # aplicação Nuxt
│   └── wazync/    # gateway de comunicação WhatsApp em Go
├── infra/docker/  # imagens de desenvolvimento e produção
├── infra/swarm/   # operação e exemplos de Docker Secrets
├── Makefile       # comandos de desenvolvimento e operação
├── docker-compose.yml # ambiente local com hot reload
└── docker-stack.yml   # runtime imutável para Docker Swarm
```

## Executando localmente

### Pré-requisitos

- Git;
- Docker com o plugin Docker Compose;
- GNU Make;
- OpenSSL.

As dependências de PHP, Node.js e Go são executadas nos containers, portanto não precisam ser instaladas diretamente na máquina.

### Instalação

```bash
git clone https://github.com/felipyfgs/kontivehub.git
cd kontivehub
make setup
```

O `make setup` cria os arquivos `.env` a partir dos exemplos, gera chaves locais, constrói as imagens, instala as dependências, executa as migrations e inicia a stack.

Nas próximas execuções, suba toda a stack com:

```bash
make up
```

Depois que os serviços estiverem saudáveis, acesse:

- aplicação Nuxt: [http://localhost:3000](http://localhost:3000);
- API e edge Nginx: [http://localhost:8080](http://localhost:8080);
- health check: [http://localhost:8080/up](http://localhost:8080/up).

Se quiser carregar os dados de desenvolvimento:

```bash
make seed
```

As credenciais locais padrão estão documentadas no arquivo [`.env.example`](.env.example). Altere-as se o ambiente puder ser acessado por outras pessoas e nunca versione os arquivos `.env` reais.

## Comandos úteis

| Comando | Descrição |
| --- | --- |
| `make help` | Lista os principais comandos disponíveis |
| `make up` | Inicia toda a stack com Nuxt HMR |
| `make down` | Encerra os serviços locais |
| `make logs` | Acompanha os logs dos containers |
| `make shell` | Abre um shell no container PHP |
| `make migrate` | Executa as migrations pendentes |
| `make seed` | Carrega os dados de desenvolvimento |
| `make build` | Reconstrói as imagens locais |

## Testes e qualidade

Execute todos os gates do monorepo com:

```bash
make verify
```

Também é possível validar cada aplicação separadamente:

```bash
make verify-api
make verify-web
make verify-wazync
```

A suíte isolada da API pode ser executada com `make api-test`. Os testes end-to-end do frontend ficam disponíveis em `apps/web` pelo script `pnpm test:e2e`.

## Configuração e segurança

- `.env` concentra as variáveis usadas pelo Docker Compose;
- `apps/api/.env` contém a configuração específica do Laravel no ambiente local;
- integrações externas, comunicação, automações e mutações fiscais usam defaults *fail-closed*;
- segredos, certificados, tokens e chaves privadas não devem ser enviados ao Git;
- o contrato público da API está em [`apps/api/resources/contracts/public.openapi.json`](apps/api/resources/contracts/public.openapi.json).

Consulte os comentários de [`.env.example`](.env.example) para conhecer as
variáveis exclusivamente locais. O `docker-compose.yml` usa bind mounts: Nuxt
executa com HMR, Laravel lê o código montado diretamente e Wazync recompila com
Air quando arquivos Go mudam.

Produção usa outro caminho. Após o CI da branch `main`, a pipeline constrói e
publica imagens multi-stage versionadas para API, Web, Wazync e Nginx. O
[`docker-stack.yml`](docker-stack.yml) apenas referencia essas imagens e
serviços oficiais de PostgreSQL/Redis; ele não compila nem monta o repositório
no servidor. Configuração e material sensível são fornecidos por Docker
Secrets. O procedimento completo está em
[`infra/swarm/README.md`](infra/swarm/README.md).

## Contribuindo

O projeto usa `develop` como branch de integração e `main` como branch estável. Antes de abrir um pull request, execute os gates relacionados às áreas alteradas.

Leia o [guia de contribuição](CONTRIBUTING.md) para conhecer o fluxo de branches e as verificações obrigatórias.
