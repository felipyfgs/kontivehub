/**
 * Copy de UI — regra do produto: breve e objetivo.
 *
 * Preferir:
 * - 1 linha de subtítulo (sem “—” longos nem jargão)
 * - empty states em 2–4 palavras + descrição curta
 * - timestamps como “· HH:mm”, sem “atualizado …”
 * - sem repetir contexto já no título da página
 *
 * Títulos longos de KPI: usar `kpiDisplayTitle` / truncate.
 */

/** Junta partes de meta (hora, data) com “ · ”, ignora vazios. */
export function uiMetaLine(...parts: Array<string | null | undefined>): string {
  return parts
    .map(p => (p == null ? '' : String(p).trim()))
    .filter(Boolean)
    .join(' · ')
}
