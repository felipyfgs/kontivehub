<?php

namespace App\Services\Integra;

use Carbon\CarbonImmutable;
use DOMDocument;
use InvalidArgumentException;

/**
 * Gerador canônico do termoDeAutorizacao (layout documental fixado).
 *
 * Textos legais e atributos oficiais — sem alteração semântica.
 * Schema local é DERIVED (não XSD oficial SERPRO).
 */
final class TermoAutorizacaoGenerator
{
    public const SCHEMA_VERSION = '1.0.0-derived';

    public const SISTEMA_ID = 'API Integra Contador';

    public const TERMO_TEXTO = 'Autorizo a empresa CONTRATANTE, identificada neste termo de autorização como DESTINATÁRIO, a executar as requisições dos serviços web disponibilizados pela API INTEGRA CONTADOR, onde terei o papel de AUTOR PEDIDO DE DADOS no corpo da mensagem enviada na requisição do serviço web. Esse termo de autorização está assinado digitalmente com o certificado digital do PROCURADOR ou OUTORGADO DO CONTRIBUINTE responsável, identificado como AUTOR DO PEDIDO DE DADOS.';

    public const AVISO_LEGAL_TEXTO = 'O acesso a estas informações foi autorizado pelo próprio PROCURADOR ou OUTORGADO DO CONTRIBUINTE, responsável pela informação, via assinatura digital. É dever do destinatário da autorização e consumidor deste acesso observar a adoção de base legal para o tratamento dos dados recebidos conforme artigos 7º ou 11º da LGPD (Lei n.º 13.709, de 14 de agosto de 2018), aos direitos do titular dos dados (art. 9º, 17 e 18, da LGPD) e aos princípios que norteiam todos os tratamentos de dados no Brasil (art. 6º, da LGPD).';

    public const FINALIDADE_TEXTO = 'A finalidade única e exclusiva desse TERMO DE AUTORIZAÇÃO, é garantir que o CONTRATANTE apresente a API INTEGRA CONTADOR esse consentimento do PROCURADOR ou OUTORGADO DO CONTRIBUINTE assinado digitalmente, para que possa realizar as requisições dos serviços web da API INTEGRA CONTADOR em nome do AUTOR PEDIDO DE DADOS (PROCURADOR ou OUTORGADO DO CONTRIBUINTE).';

    public const DESTINATARIO_PAPEL = 'contratante';

    public const ASSINADO_POR_PAPEL = 'autor pedido de dados';

    /**
     * Gera XML não assinado, determinístico (sem indentação / quebras extras).
     *
     * @param  'PF'|'PJ'  $authorTipo
     */
    public function generateUnsigned(
        string $destinationCnpj,
        string $destinationName,
        string $authorIdentity,
        string $authorName,
        string $authorTipo,
        CarbonImmutable|string $dataAssinatura,
        CarbonImmutable|string $vigencia,
    ): string {
        $destNi = $this->normalizeNi($destinationCnpj);
        $authorNi = $this->normalizeNi($authorIdentity);
        $tipo = strtoupper($authorTipo);

        if (strlen($destNi) !== 14) {
            throw new InvalidArgumentException('CNPJ do destinatário deve ter 14 caracteres.');
        }
        if (! in_array(strlen($authorNi), [11, 14], true)) {
            throw new InvalidArgumentException('Identidade do autor deve ter 11 (CPF) ou 14 (CNPJ) caracteres.');
        }
        if ($tipo === 'PF' && strlen($authorNi) !== 11) {
            throw new InvalidArgumentException('Autor PF exige CPF com 11 caracteres.');
        }
        if ($tipo === 'PJ' && strlen($authorNi) !== 14) {
            throw new InvalidArgumentException('Autor PJ exige CNPJ com 14 caracteres.');
        }
        if (! in_array($tipo, ['PF', 'PJ'], true)) {
            throw new InvalidArgumentException('tipo do autor deve ser PF ou PJ.');
        }

        $dataAss = $this->formatYmd($dataAssinatura);
        $dataVig = $this->formatYmd($vigencia);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = false;

        $root = $dom->createElement('termoDeAutorizacao');
        $dom->appendChild($root);

        $dados = $dom->createElement('dados');
        $root->appendChild($dados);

        $sistema = $dom->createElement('sistema');
        $sistema->setAttribute('id', self::SISTEMA_ID);
        $dados->appendChild($sistema);

        $termo = $dom->createElement('termo');
        $termo->setAttribute('texto', self::TERMO_TEXTO);
        $dados->appendChild($termo);

        $aviso = $dom->createElement('avisoLegal');
        $aviso->setAttribute('texto', self::AVISO_LEGAL_TEXTO);
        $dados->appendChild($aviso);

        $finalidade = $dom->createElement('finalidade');
        $finalidade->setAttribute('texto', self::FINALIDADE_TEXTO);
        $dados->appendChild($finalidade);

        $dataAssinaturaEl = $dom->createElement('dataAssinatura');
        $dataAssinaturaEl->setAttribute('data', $dataAss);
        $dados->appendChild($dataAssinaturaEl);

        $vigenciaEl = $dom->createElement('vigencia');
        $vigenciaEl->setAttribute('data', $dataVig);
        $dados->appendChild($vigenciaEl);

        $dest = $dom->createElement('destinatario');
        $dest->setAttribute('numero', $destNi);
        $dest->setAttribute('nome', $this->escapeAttrValue($destinationName));
        $dest->setAttribute('tipo', 'PJ');
        $dest->setAttribute('papel', self::DESTINATARIO_PAPEL);
        $dados->appendChild($dest);

        $assinado = $dom->createElement('assinadoPor');
        $assinado->setAttribute('numero', $authorNi);
        $assinado->setAttribute('nome', $this->escapeAttrValue($authorName));
        $assinado->setAttribute('tipo', $tipo);
        $assinado->setAttribute('papel', self::ASSINADO_POR_PAPEL);
        $dados->appendChild($assinado);

        // XML declaration + compact root (sem whitespace entre elementos).
        $xml = $dom->saveXML($root);
        if ($xml === false || $xml === '') {
            throw new InvalidArgumentException('Falha ao serializar termoDeAutorizacao.');
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'.$xml;
    }

    private function normalizeNi(string $raw): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $raw) ?? '');
    }

    private function formatYmd(CarbonImmutable|string $value): string
    {
        if ($value instanceof CarbonImmutable) {
            return $value->format('Ymd');
        }
        $raw = trim($value);
        if (preg_match('/^\d{8}$/', $raw)) {
            return $raw;
        }
        try {
            return CarbonImmutable::parse($raw)->format('Ymd');
        } catch (\Throwable) {
            throw new InvalidArgumentException('Data inválida para Termo (esperado AAAAMMDD).');
        }
    }

    /**
     * DOM setAttribute already escapes; keep control chars out of names.
     */
    private function escapeAttrValue(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Nome do participante é obrigatório.');
        }

        // Reject control characters that break XML or hide UNICODE traps.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $name)) {
            throw new InvalidArgumentException('Nome contém caracteres de controle inválidos.');
        }

        return $name;
    }
}
