<?php

namespace App\Services\Esocial;

use App\Enums\EsocialEventCode;
use App\Exceptions\EsocialBxException;
use DOMDocument;
use DOMElement;

final class EsocialBxRequestFactory
{
    private const SOAP_NS = 'http://schemas.xmlsoap.org/soap/envelope/';

    private const IDENTIFIERS_SERVICE_NS = 'http://www.esocial.gov.br/servicos/empregador/consulta/identificadores-eventos/v1_0_0';

    private const DOWNLOAD_SERVICE_NS = 'http://www.esocial.gov.br/servicos/empregador/download/solicitacao/v1_0_0';

    public function __construct(
        private readonly EsocialBxXmlSigner $signer,
        private readonly EsocialBxConfig $config,
    ) {}

    /** @return array{operation:string,endpoint:string,soap_action:string,envelope:string} */
    public function identifiers(
        string $environment,
        string $employerNumber,
        EsocialEventCode $eventCode,
        string $competence,
        string $pfxBinary,
        string $password,
    ): array {
        if (! in_array($eventCode, [EsocialEventCode::S1299, EsocialEventCode::S5013], true)) {
            throw new EsocialBxException(
                'ESOCIAL_BX_EVENT_NOT_AUTOMATIC',
                'O evento solicitado não possui consulta automática agregada no provider eSocial BX.',
                blocked: true,
            );
        }
        $this->assertCompetence($competence);

        $payload = $this->payload(
            'http://www.esocial.gov.br/schema/consulta/identificadores-eventos/empregador/v1_0_0',
            function (DOMDocument $dom, DOMElement $root) use ($employerNumber, $eventCode, $competence): void {
                $query = $root->appendChild($dom->createElement('consultaIdentificadoresEvts'));
                $identity = $query->appendChild($dom->createElement('ideEmpregador'));
                $identity->appendChild($dom->createElement('tpInsc', '1'));
                $identity->appendChild($dom->createElement('nrInsc', $this->cnpjRoot($employerNumber)));
                $filter = $query->appendChild($dom->createElement('consultaEvtsEmpregador'));
                $filter->appendChild($dom->createElement('tpEvt', $eventCode->value));
                $filter->appendChild($dom->createElement('perApur', $competence));
            },
        );
        $signed = $this->signer->sign($payload, $pfxBinary, $password);
        $method = 'ConsultarIdentificadoresEventosEmpregador';

        return [
            'operation' => 'IDENTIFIERS_'.$eventCode->value,
            'endpoint' => $this->config->endpoint($environment, 'identifiers'),
            'soap_action' => self::IDENTIFIERS_SERVICE_NS.'/ServicoConsultarIdentificadoresEventos/'.$method,
            'envelope' => $this->envelope(
                self::IDENTIFIERS_SERVICE_NS,
                $method,
                'consultaEventosEmpregador',
                $signed,
            ),
        ];
    }

    /** @param list<string> $ids
     * @return array{operation:string,endpoint:string,soap_action:string,envelope:string}
     */
    public function downloadByIds(
        string $environment,
        string $employerNumber,
        array $ids,
        string $pfxBinary,
        string $password,
    ): array {
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
        $max = (int) config('fgts_esocial.official_bx.batch_limit', 50);
        if ($ids === [] || count($ids) > $max) {
            throw new EsocialBxException(
                'ESOCIAL_BX_INVALID_DOWNLOAD_BATCH',
                "O lote de download eSocial BX deve conter entre 1 e {$max} identificadores.",
                blocked: true,
            );
        }

        $payload = $this->payload(
            'http://www.esocial.gov.br/schema/download/solicitacao/id/v1_0_0',
            function (DOMDocument $dom, DOMElement $root) use ($employerNumber, $ids): void {
                $download = $root->appendChild($dom->createElement('download'));
                $identity = $download->appendChild($dom->createElement('ideEmpregador'));
                $identity->appendChild($dom->createElement('tpInsc', '1'));
                $identity->appendChild($dom->createElement('nrInsc', $this->cnpjRoot($employerNumber)));
                $selection = $download->appendChild($dom->createElement('solicDownloadEvtsPorId'));
                foreach ($ids as $id) {
                    if (! preg_match('/^ID[0-9A-Za-z_-]{10,80}$/', $id)) {
                        throw new EsocialBxException(
                            'ESOCIAL_BX_INVALID_EVENT_ID',
                            'Identificador de evento eSocial inválido.',
                            blocked: true,
                        );
                    }
                    $selection->appendChild($dom->createElement('id', $id));
                }
            },
        );
        $signed = $this->signer->sign($payload, $pfxBinary, $password);
        $method = 'SolicitarDownloadEventosPorId';

        return [
            'operation' => 'DOWNLOAD_BY_ID',
            'endpoint' => $this->config->endpoint($environment, 'downloads'),
            'soap_action' => self::DOWNLOAD_SERVICE_NS.'/ServicoSolicitarDownloadEventos/'.$method,
            'envelope' => $this->envelope(self::DOWNLOAD_SERVICE_NS, $method, 'solicitacao', $signed),
        ];
    }

    private function payload(string $namespace, callable $build): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = false;
        $root = $dom->createElementNS($namespace, 'eSocial');
        $dom->appendChild($root);
        $build($dom, $root);

        return $dom->saveXML($root) ?: throw new EsocialBxException(
            'ESOCIAL_BX_REQUEST_BUILD_FAILED',
            'Não foi possível montar a solicitação eSocial BX.',
            blocked: true,
        );
    }

    private function envelope(string $serviceNamespace, string $method, string $parameter, string $signed): string
    {
        $payload = new DOMDocument;
        if (! $payload->loadXML($signed, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new EsocialBxException('ESOCIAL_BX_SIGNED_XML_INVALID', 'XML assinado inválido.', blocked: true);
        }

        $soap = new DOMDocument('1.0', 'UTF-8');
        $envelope = $soap->createElementNS(self::SOAP_NS, 'soapenv:Envelope');
        $soap->appendChild($envelope);
        $envelope->appendChild($soap->createElementNS(self::SOAP_NS, 'soapenv:Header'));
        $body = $envelope->appendChild($soap->createElementNS(self::SOAP_NS, 'soapenv:Body'));
        $operation = $body->appendChild($soap->createElementNS($serviceNamespace, 'v1:'.$method));
        $wrapper = $operation->appendChild($soap->createElementNS($serviceNamespace, 'v1:'.$parameter));
        $wrapper->appendChild($soap->importNode($payload->documentElement, true));

        return $soap->saveXML($envelope) ?: throw new EsocialBxException(
            'ESOCIAL_BX_ENVELOPE_BUILD_FAILED',
            'Não foi possível montar o envelope eSocial BX.',
            blocked: true,
        );
    }

    private function cnpjRoot(string $value): string
    {
        if (preg_match('/^[0-9.\/-]+$/', $value) !== 1) {
            throw new EsocialBxException('ESOCIAL_BX_EMPLOYER_INVALID', 'CNPJ do empregador inválido.', blocked: true);
        }
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (! in_array(strlen($digits), [8, 14], true)) {
            throw new EsocialBxException('ESOCIAL_BX_EMPLOYER_INVALID', 'CNPJ do empregador inválido.', blocked: true);
        }

        return substr($digits, 0, 8);
    }

    private function assertCompetence(string $competence): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competence) !== 1) {
            throw new EsocialBxException(
                'ESOCIAL_BX_COMPETENCE_INVALID',
                'Competência da solicitação eSocial BX inválida.',
                blocked: true,
            );
        }
    }
}
