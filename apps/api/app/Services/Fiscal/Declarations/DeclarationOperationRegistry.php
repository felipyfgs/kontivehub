<?php

namespace App\Services\Fiscal\Declarations;

use InvalidArgumentException;

/**
 * Allowlist pública das operações declarativas oficiais.
 *
 * O navegador trabalha somente com action_id. operation_key e coordenadas
 * permanecem internos e são resolvidos por este registro + catálogo oficial.
 */
final class DeclarationOperationRegistry
{
    /** @var array<string, string> */
    private const ACTION_IDS = [
        'pgdasd.transdeclaracao' => 'decl_pgdas_entregar',
        'pgdasd.gerardas' => 'decl_pgdas_gerar_das',
        'pgdasd.consdeclaracao' => 'decl_pgdas_consultar_declaracoes',
        'pgdasd.consultimadecrec' => 'decl_pgdas_consultar_ultima',
        'pgdasd.consdecrec' => 'decl_pgdas_consultar_recibo',
        'pgdasd.consextrato' => 'decl_pgdas_consultar_extrato',
        'pgdasd.gerardascobranca' => 'decl_pgdas_gerar_das_cobranca',
        'pgdasd.gerardasprocesso' => 'decl_pgdas_gerar_das_processo',
        'pgdasd.gerardasavulso' => 'decl_pgdas_gerar_das_avulso',
        'defis.transdeclaracao' => 'decl_defis_transmitir',
        'defis.consdeclaracao' => 'decl_defis_consultar_declaracoes',
        'defis.consultimadecrec' => 'decl_defis_consultar_ultima',
        'defis.consdecrec' => 'decl_defis_consultar_recibo',
        'dasnsimei.transdeclaracao' => 'decl_dasn_entregar',
        'dasnsimei.consultimadecrec' => 'decl_dasn_consultar',
        'dasnsimei.gerardasexcesso' => 'decl_dasn_gerar_das_excesso',
        'dctfweb.gerarguia' => 'decl_dctfweb_gerar_guia',
        'dctfweb.consrecibo' => 'decl_dctfweb_consultar_recibo',
        'dctfweb.consdeccompleta' => 'decl_dctfweb_consultar_completa',
        'dctfweb.consrelcredito' => 'decl_dctfweb_consultar_creditos',
        'dctfweb.consreldebito' => 'decl_dctfweb_consultar_debitos',
        'dctfweb.gerarguiamaed' => 'decl_dctfweb_gerar_guia_maed',
        'dctfweb.consnotifmaed' => 'decl_dctfweb_consultar_notificacao_maed',
        'dctfweb.consxmldeclaracao' => 'decl_dctfweb_consultar_xml',
        'dctfweb.aplvinculacao' => 'decl_dctfweb_aplicar_vinculacao',
        'dctfweb.transdeclaracao' => 'decl_dctfweb_transmitir',
        'dctfweb.gerarguiacomabatimento' => 'decl_dctfweb_gerar_guia_residual',
        'dctfweb.editarvalorsuspenso' => 'decl_dctfweb_editar_valor_suspenso',
        'dctfweb.gerarguiaandamento' => 'decl_dctfweb_gerar_guia_andamento',
        'mit.encapuracao' => 'decl_mit_encerrar',
        'mit.situacaoenc' => 'decl_mit_consultar_situacao',
        'mit.consapuracao' => 'decl_mit_consultar_apuracao',
        'mit.listaapuracoes' => 'decl_mit_listar_apuracoes',
    ];

    /** @var array<string, string> */
    private const OBLIGATIONS = [
        'PGDASD' => 'PGDAS',
        'DEFIS' => 'DEFIS',
        'DASNSIMEI' => 'DASN_SIMEI',
        'DCTFWEB' => 'DCTFWEB',
        'MIT' => 'MIT',
    ];

    /**
     * @return list<array{name: string, type: string, required: bool, label: string, format?: string, help?: string}>
     */
    public function publicParamsFor(string $operationKey): array
    {
        $period = [$this->field('period_key', 'month', true, 'Competência', 'AAAA-MM')];
        $year = [$this->field('calendar_year', 'integer', true, 'Ano-calendário', 'AAAA')];

        return match ($operationKey) {
            'pgdasd.transdeclaracao' => [
                ...$period,
                $this->field(
                    'business_payload',
                    'object',
                    true,
                    'Dados da declaração',
                    help: 'JSON de negócio conforme o contrato oficial; identidades e coordenadas são resolvidas pelo servidor.',
                ),
            ],
            'defis.transdeclaracao',
            'dasnsimei.transdeclaracao' => [
                ...$year,
                $this->field(
                    'business_payload',
                    'object',
                    true,
                    'Dados da declaração',
                    help: 'JSON de negócio conforme o contrato oficial; identidades e coordenadas são resolvidas pelo servidor.',
                ),
            ],
            'mit.encapuracao' => [
                ...$period,
                $this->field(
                    'business_payload',
                    'object',
                    true,
                    'Dados da apuração MIT',
                    help: 'JSON de negócio conforme o contrato oficial; período e coordenadas são resolvidos pelo servidor.',
                ),
            ],
            'pgdasd.gerardas', 'pgdasd.gerardascobranca' => [
                ...$period,
                $this->field('consolidation_date', 'date', false, 'Data de consolidação'),
            ],
            'pgdasd.consdeclaracao' => [
                $this->field('calendar_year', 'integer', false, 'Ano-calendário', 'AAAA'),
                $this->field('period_key', 'month', false, 'Competência', 'AAAA-MM'),
            ],
            'pgdasd.consultimadecrec' => $period,
            'pgdasd.consdecrec' => [
                ...$period,
                $this->field('declaration_number', 'string', true, 'Número da declaração'),
            ],
            'pgdasd.consextrato' => [
                $this->field('das_number', 'string', true, 'Número do DAS'),
            ],
            'pgdasd.gerardasprocesso' => [
                ...$period,
            ],
            'pgdasd.gerardasavulso' => [
                ...$period,
                $this->field('business_payload', 'object', true, 'Composição do DAS avulso'),
                $this->field('consolidation_date', 'date', false, 'Data de consolidação'),
                $this->field('special_extension', 'integer', false, 'Prorrogação especial'),
            ],
            'defis.consdeclaracao' => [],
            'defis.consultimadecrec', 'dasnsimei.consultimadecrec', 'dasnsimei.gerardasexcesso' => $year,
            'defis.consdecrec' => [
                $this->field('reference_id', 'integer', true, 'Referência observada da declaração'),
            ],
            'dctfweb.consrecibo',
            'dctfweb.consdeccompleta',
            'dctfweb.consxmldeclaracao' => $period,
            'dctfweb.gerarguia', 'dctfweb.gerarguiaandamento' => [
                ...$period,
                $this->field('category', 'string', true, 'Categoria da declaração'),
                $this->field('day', 'integer', false, 'Dia do período'),
                $this->field('cno', 'string', false, 'CNO da obra'),
                $this->field('labor_process', 'string', false, 'Processo trabalhista'),
                $this->field('source_system_ids', 'array', false, 'Sistemas de origem'),
                $this->field('proposal_date', 'date', false, 'Data de acolhimento proposta'),
            ],
            'dctfweb.transdeclaracao' => [
                ...$period,
                $this->field('category', 'string', true, 'Categoria da declaração'),
                $this->field('day', 'integer', false, 'Dia do período'),
                $this->field('cno', 'string', false, 'CNO da obra'),
                $this->field('receipt_number', 'string', false, 'Número do recibo'),
                $this->field('labor_process', 'string', false, 'Processo trabalhista'),
                $this->field('signed_xml_base64', 'base64', true, 'XML assinado em Base64'),
            ],
            'mit.situacaoenc' => [
                ...$period,
                $this->field('closing_protocol', 'string', true, 'Protocolo de encerramento'),
            ],
            'mit.consapuracao' => [
                ...$period,
                $this->field('assessment_id', 'integer', true, 'Identificador da apuração'),
            ],
            'mit.listaapuracoes' => [
                $this->field('calendar_year', 'integer', false, 'Ano de apuração', 'AAAA'),
                $this->field('month', 'integer', false, 'Mês de apuração'),
                $this->field('status', 'integer', false, 'Situação da apuração'),
            ],
            // Prospecção sem contrato público estável não recebe formulário.
            default => [],
        };
    }

    public function actionIdFor(string $operationKey): string
    {
        return self::ACTION_IDS[$operationKey]
            ?? throw new InvalidArgumentException("Operação declarativa não allowlisted: {$operationKey}");
    }

    public function operationKeyFor(string $actionId): string
    {
        $operationKey = array_search($actionId, self::ACTION_IDS, true);
        if (! is_string($operationKey)) {
            throw new InvalidArgumentException("Ação declarativa desconhecida: {$actionId}");
        }

        return $operationKey;
    }

    public function obligationForSystem(string $idSistema): string
    {
        return self::OBLIGATIONS[strtoupper($idSistema)]
            ?? throw new InvalidArgumentException("Sistema fora do domínio declarativo: {$idSistema}");
    }

    public function isDeclarationSystem(string $idSistema): bool
    {
        return isset(self::OBLIGATIONS[strtoupper($idSistema)]);
    }

    /** @return list<string> */
    public function operationKeys(): array
    {
        return array_keys(self::ACTION_IDS);
    }

    /**
     * @return array{name: string, type: string, required: bool, label: string, format?: string, help?: string}
     */
    private function field(
        string $name,
        string $type,
        bool $required,
        string $label,
        ?string $format = null,
        ?string $help = null,
    ): array {
        return array_filter([
            'name' => $name,
            'type' => $type,
            'required' => $required,
            'label' => $label,
            'format' => $format,
            'help' => $help,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
