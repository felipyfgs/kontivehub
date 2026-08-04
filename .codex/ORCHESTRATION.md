# Orquestração

Use subagentes para entregas delimitadas, verificáveis e com pouca coordenação.
Evite fan-out em tarefas pequenas, sequenciais ou com ownership sobreposto.

## Roteamento

| Agente | Modelo | Uso |
|---|---|---|
| `explorer` | Luna/medium | investigação e documentação delimitadas |
| `reviewer` | Luna/high | review de diff estável e delimitado |
| `worker` | Terra/medium | implementação e testes focados |
| `expert` | Sol/max | arquitetura, segurança, concorrência e impasses |

Prefira Luna em leitura/review estreitos, Terra para execução e Sol apenas para
alto risco ou ambiguidade. Se Luna não estiver disponível, use Terra e informe
o fallback uma vez; não repita tentativas em loop.

## Contrato da subtarefa

Informe objetivo, entrega esperada, ownership/caminhos proibidos, contexto,
critérios de aceite, validações e formato de retorno. Avise que há outros
agentes e que alterações alheias não podem ser revertidas. O retorno deve conter
somente conclusão, evidências, arquivos, testes e riscos — nunca logs brutos.

## Execução e aceitação

```text
exploração → implementação → testes → review → correção → gates finais
```

- Apenas o principal cria subagentes; profundidade máxima 1.
- Comece com 2–4 agentes somente para frentes independentes; até 6 apenas em
  fan-out read-only útil.
- Um único writer possui cada arquivo, contrato, migration, lockfile, Compose ou
  workflow por fase. Writers paralelos exigem ownership disjunto.
- Subagentes não fazem merge, rebase, push nem resolvem trabalho de outro agente.
- Correções materiais passam por novo review.
- O principal/default aceita ou rejeita toda edição após inspecionar o diff,
  ownership, alterações alheias, testes, limites arquiteturais e segredos.
- Reviewer e testes são evidências; não substituem o gate do principal.

Perfis read-only nunca editam, mesmo se o runtime herdar permissões maiores da
sessão pai. O principal integra resultados e reexecuta os gates afetados.
