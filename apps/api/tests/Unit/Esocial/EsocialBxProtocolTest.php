<?php

namespace Tests\Unit\Esocial;

use App\Enums\EsocialEventCode;
use App\Exceptions\EsocialBxException;
use App\Services\Esocial\EsocialBxConfig;
use App\Services\Esocial\EsocialBxRequestFactory;
use App\Services\Esocial\EsocialBxResponseParser;
use App\Services\Esocial\EsocialBxXmlSigner;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class EsocialBxProtocolTest extends TestCase
{
    public function test_official_request_is_signed_and_wrapped_as_soap_11_without_secrets(): void
    {
        [$pfx, $password] = $this->makePfx();
        $factory = new EsocialBxRequestFactory(new EsocialBxXmlSigner, app(EsocialBxConfig::class));

        $request = $factory->identifiers(
            'restricted',
            '48123272000105',
            EsocialEventCode::S1299,
            '2026-06',
            $pfx,
            $password,
        );

        $this->assertSame('IDENTIFIERS_S-1299', $request['operation']);
        $this->assertSame(
            'http://www.esocial.gov.br/servicos/empregador/consulta/identificadores-eventos/v1_0_0/ServicoConsultarIdentificadoresEventos/ConsultarIdentificadoresEventosEmpregador',
            $request['soap_action'],
        );
        $this->assertStringContainsString('http://schemas.xmlsoap.org/soap/envelope/', $request['envelope']);
        $this->assertStringContainsString('<tpEvt>S-1299</tpEvt>', $request['envelope']);
        $this->assertStringContainsString('<perApur>2026-06</perApur>', $request['envelope']);
        $this->assertStringContainsString('<nrInsc>48123272</nrInsc>', $request['envelope']);
        $this->assertStringContainsString('Signature', $request['envelope']);
        $this->assertStringContainsString('rsa-sha256', $request['envelope']);
        $this->assertStringNotContainsString($password, $request['envelope']);

        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($request['envelope'], LIBXML_NONET));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('svc', 'http://www.esocial.gov.br/servicos/empregador/consulta/identificadores-eventos/v1_0_0');
        $xpath->registerNamespace('req', 'http://www.esocial.gov.br/schema/consulta/identificadores-eventos/empregador/v1_0_0');
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $this->assertSame(1, $xpath->query('/soap:Envelope/soap:Body/svc:ConsultarIdentificadoresEventosEmpregador/svc:consultaEventosEmpregador/req:eSocial')->length);
        $this->assertSame(1, $xpath->query('//req:eSocial/ds:Signature')->length);
        $this->assertSame('', $xpath->evaluate('string(//ds:Reference/@URI)'));
        $this->assertSame(
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
            $xpath->evaluate('string(//ds:SignatureMethod/@Algorithm)'),
        );
        $this->assertSame(
            'http://www.w3.org/2001/04/xmlenc#sha256',
            $xpath->evaluate('string(//ds:DigestMethod/@Algorithm)'),
        );
        $this->assertSame(1, $xpath->query('//ds:X509Certificate')->length);
        $this->assertSame(0, $xpath->query('//ds:RSAKeyValue|//ds:PrivateKey')->length);
    }

    public function test_download_batch_is_limited_and_uses_official_action(): void
    {
        [$pfx, $password] = $this->makePfx();
        $factory = new EsocialBxRequestFactory(new EsocialBxXmlSigner, app(EsocialBxConfig::class));
        $request = $factory->downloadByIds(
            'restricted',
            '48123272',
            ['ID12345678901234567890', 'ID09876543210987654321'],
            $pfx,
            $password,
        );

        $this->assertSame(
            'http://www.esocial.gov.br/servicos/empregador/download/solicitacao/v1_0_0/ServicoSolicitarDownloadEventos/SolicitarDownloadEventosPorId',
            $request['soap_action'],
        );
        $this->assertSame(2, substr_count($request['envelope'], '<id>'));

        $this->expectException(EsocialBxException::class);
        $factory->downloadByIds('restricted', '48123272', [], $pfx, $password);
    }

    public function test_request_factory_enforces_official_batch_competence_and_dom_input_invariants(): void
    {
        [$pfx, $password] = $this->makePfx();
        $factory = new EsocialBxRequestFactory(new EsocialBxXmlSigner, app(EsocialBxConfig::class));
        $ids = array_map(
            static fn (int $index): string => 'ID'.str_pad((string) $index, 20, '0', STR_PAD_LEFT),
            range(1, 50),
        );
        $request = $factory->downloadByIds('restricted', '48.123.272/0001-05', $ids, $pfx, $password);
        $this->assertSame(50, substr_count($request['envelope'], '<id>'));

        try {
            $factory->downloadByIds(
                'restricted',
                '48123272',
                [...$ids, 'ID'.str_repeat('9', 20)],
                $pfx,
                $password,
            );
            $this->fail('Lote acima de 50 deveria falhar.');
        } catch (EsocialBxException $exception) {
            $this->assertSame('ESOCIAL_BX_INVALID_DOWNLOAD_BATCH', $exception->stableCode);
        }

        try {
            $factory->identifiers(
                'restricted',
                '48123272"><unsafe',
                EsocialEventCode::S1299,
                '2026-06',
                $pfx,
                $password,
            );
            $this->fail('Identificador com markup deveria falhar.');
        } catch (EsocialBxException $exception) {
            $this->assertSame('ESOCIAL_BX_EMPLOYER_INVALID', $exception->stableCode);
        }

        try {
            $factory->identifiers(
                'restricted',
                '48123272',
                EsocialEventCode::S1299,
                '2026-13',
                $pfx,
                $password,
            );
            $this->fail('Competência inválida deveria falhar.');
        } catch (EsocialBxException $exception) {
            $this->assertSame('ESOCIAL_BX_COMPETENCE_INVALID', $exception->stableCode);
        }
    }

    public function test_parser_handles_identifiers_empty_partial_and_downloaded_event(): void
    {
        $parser = new EsocialBxResponseParser;
        $identifiers = $parser->identifiers($this->soap(<<<'XML'
<eSocial xmlns="http://www.esocial.gov.br/schema/consulta/identificadores-eventos/retorno/v1_0_0">
  <retornoConsultaIdentificadoresEvts>
    <status><cdResposta>203</cdResposta><descResposta>Sucesso parcial</descResposta></status>
    <retornoIdentificadoresEvts>
      <qtdeTotEvtsConsulta>51</qtdeTotEvtsConsulta>
      <identificadoresEvts>
        <identificadorEvt><id>ID12345678901234567890</id><nrRec>1.2.000000000000001</nrRec></identificadorEvt>
      </identificadoresEvts>
    </retornoIdentificadoresEvts>
  </retornoConsultaIdentificadoresEvts>
</eSocial>
XML));
        $this->assertTrue($identifiers->partial);
        $this->assertSame('ID12345678901234567890', $identifiers->identifiers[0]->id);

        $empty = $parser->identifiers($this->soap(<<<'XML'
<eSocial xmlns="http://www.esocial.gov.br/schema/consulta/identificadores-eventos/retorno/v1_0_0">
  <retornoConsultaIdentificadoresEvts><status><cdResposta>406</cdResposta><descResposta>Sem registros</descResposta></status></retornoConsultaIdentificadoresEvts>
</eSocial>
XML));
        $this->assertSame([], $empty->identifiers);

        $download = $parser->downloads(
            $this->soap(<<<'XML'
<eSocial xmlns="http://www.esocial.gov.br/schema/download/solicitacao/retorno/v1_0_0">
  <download>
    <status><cdResposta>201</cdResposta><descResposta>Sucesso</descResposta></status>
    <retornoSolicDownloadEvts><arquivos><arquivo>
      <status><cdResposta>201</cdResposta><descResposta>Evento encontrado</descResposta></status>
      <evt Id="ID12345678901234567890">
        <eSocial xmlns="http://www.esocial.gov.br/schema/evt/evtFechaEvPer/v_S_01_03_00">
          <evtFechaEvPer Id="ID12345678901234567890"><ideEvento><perApur>2026-06</perApur></ideEvento></evtFechaEvPer>
        </eSocial>
      </evt>
      <rec nrRec="1.2.000000000000001"><eSocial xmlns="urn:receipt"><recibo/></eSocial></rec>
    </arquivo></arquivos></retornoSolicDownloadEvts>
  </download>
</eSocial>
XML),
            EsocialEventCode::S1299,
            '2026-06',
        );
        $this->assertFalse($download->partial);
        $this->assertCount(1, $download->events);
        $this->assertSame('1.2.000000000000001', $download->events[0]->receiptNumber);
        $this->assertSame('2026-06', $download->events[0]->competencePeriodKey);
        $this->assertArrayNotHasKey('payloadBytes', $download->events[0]->toSanitizedArray());
    }

    public function test_parser_rejects_fault_and_competence_mismatch_with_stable_codes(): void
    {
        $parser = new EsocialBxResponseParser;

        try {
            $parser->identifiers($this->soap('<soap:Fault xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><faultcode>Server</faultcode></soap:Fault>'));
            $this->fail('SOAP Fault deveria falhar.');
        } catch (EsocialBxException $e) {
            $this->assertSame('ESOCIAL_BX_SOAP_FAULT', $e->stableCode);
        }

        try {
            $parser->downloads(
                $this->soap(<<<'XML'
<eSocial xmlns="urn:return"><download>
  <status><cdResposta>201</cdResposta><descResposta>Sucesso</descResposta></status>
  <retornoSolicDownloadEvts><arquivos><arquivo>
    <status><cdResposta>201</cdResposta></status>
    <evt Id="ID12345678901234567890"><eSocial xmlns="urn:event"><evtFechaEvPer><ideEvento><perApur>2026-05</perApur></ideEvento></evtFechaEvPer></eSocial></evt>
  </arquivo></arquivos></retornoSolicDownloadEvts>
</download></eSocial>
XML),
                EsocialEventCode::S1299,
                '2026-06',
            );
            $this->fail('Competência divergente deveria falhar.');
        } catch (EsocialBxException $e) {
            $this->assertSame('ESOCIAL_BX_EVENT_COMPETENCE_MISMATCH', $e->stableCode);
            $this->assertTrue($e->blocked);
        }
    }

    public function test_parser_maps_official_failures_without_leaking_remote_description(): void
    {
        $parser = new EsocialBxResponseParser;
        $cases = [
            '301' => ['ESOCIAL_BX_OFFICIAL_TEMPORARY', true, false],
            '307' => ['ESOCIAL_BX_OFFICIAL_TEMPORARY', true, false],
            '308' => ['ESOCIAL_BX_OFFICIAL_TEMPORARY', true, false],
            '309' => ['ESOCIAL_BX_OFFICIAL_TEMPORARY', true, false],
            '310' => ['ESOCIAL_BX_OFFICIAL_TEMPORARY', true, false],
            '402' => ['ESOCIAL_BX_REQUEST_REJECTED', false, false],
            '403' => ['ESOCIAL_BX_BLOCKED_WINDOW', false, true],
            '404' => ['ESOCIAL_BX_CONCURRENT_REQUEST', true, false],
            '405' => ['ESOCIAL_BX_QUOTA_EXHAUSTED', false, true],
            '407' => ['ESOCIAL_BX_AUTHORIZATION_DENIED', false, true],
            '408' => ['ESOCIAL_BX_REQUEST_REJECTED', false, false],
            '409' => ['ESOCIAL_BX_MINIMUM_LAG', false, false],
            '410' => ['ESOCIAL_BX_INTERVAL_LIMIT', false, false],
            '411' => ['ESOCIAL_BX_CERTIFICATE_MISMATCH', false, true],
            '417' => ['ESOCIAL_BX_REQUEST_REJECTED', false, false],
        ];

        foreach ($cases as $officialCodeValue => [$stableCode, $retryable, $blocked]) {
            $officialCode = (string) $officialCodeValue;
            try {
                $parser->identifiers($this->soap(sprintf(
                    '<eSocial xmlns="urn:return"><retornoConsultaIdentificadoresEvts><status><cdResposta>%s</cdResposta><descResposta>SEGREDO REMOTO %s</descResposta></status></retornoConsultaIdentificadoresEvts></eSocial>',
                    $officialCode,
                    $officialCode,
                )));
                $this->fail("Código oficial {$officialCode} deveria falhar.");
            } catch (EsocialBxException $exception) {
                $this->assertSame($stableCode, $exception->stableCode);
                $this->assertSame($officialCode, $exception->officialCode);
                $this->assertSame($retryable, $exception->retryable);
                $this->assertSame($blocked, $exception->blocked);
                $this->assertStringNotContainsString('SEGREDO REMOTO', $exception->getMessage());
                $this->assertStringNotContainsString(
                    'SEGREDO REMOTO',
                    json_encode($exception->toSanitizedArray(), JSON_THROW_ON_ERROR),
                );
            }
        }
    }

    public function test_parser_rejects_malformed_xml_invalid_identifier_and_download_partial_code(): void
    {
        $parser = new EsocialBxResponseParser;

        try {
            $parser->identifiers('<soap:Envelope>');
            $this->fail('XML malformado deveria falhar.');
        } catch (EsocialBxException $exception) {
            $this->assertSame('ESOCIAL_BX_RESPONSE_MALFORMED', $exception->stableCode);
            $this->assertTrue($exception->retryable);
        }

        try {
            $parser->identifiers($this->soap(<<<'XML'
<eSocial xmlns="urn:return"><retornoConsultaIdentificadoresEvts>
  <status><cdResposta>201</cdResposta><descResposta>Sucesso</descResposta></status>
  <retornoIdentificadoresEvts><identificadoresEvts><identificadorEvt><id>INVALID</id></identificadorEvt></identificadoresEvts></retornoIdentificadoresEvts>
</retornoConsultaIdentificadoresEvts></eSocial>
XML));
            $this->fail('Identificador inválido deveria falhar.');
        } catch (EsocialBxException $exception) {
            $this->assertSame('ESOCIAL_BX_IDENTIFIER_INVALID', $exception->stableCode);
        }

        try {
            $parser->downloads(
                $this->soap('<eSocial xmlns="urn:return"><download><status><cdResposta>203</cdResposta><descResposta>Parcial indevido</descResposta></status></download></eSocial>'),
                EsocialEventCode::S1299,
                '2026-06',
            );
            $this->fail('Código 203 não é sucesso oficial no serviço de download.');
        } catch (EsocialBxException $exception) {
            $this->assertSame('ESOCIAL_BX_OFFICIAL_REJECTION', $exception->stableCode);
            $this->assertSame('203', $exception->officialCode);
        }
    }

    public function test_parser_deduplicates_identifiers_and_handles_s5013_with_missing_file(): void
    {
        $parser = new EsocialBxResponseParser;
        $identifiers = $parser->identifiers($this->soap(<<<'XML'
<eSocial xmlns="urn:return"><retornoConsultaIdentificadoresEvts>
  <status><cdResposta>201</cdResposta><descResposta>Sucesso</descResposta></status>
  <retornoIdentificadoresEvts><qtdeTotEvtsConsulta>2</qtdeTotEvtsConsulta><identificadoresEvts>
    <identificadorEvt><id>ID12345678901234567890</id><nrRec>1.2.3</nrRec></identificadorEvt>
    <identificadorEvt><id>ID12345678901234567890</id><nrRec>1.2.3</nrRec></identificadorEvt>
  </identificadoresEvts></retornoIdentificadoresEvts>
</retornoConsultaIdentificadoresEvts></eSocial>
XML));
        $this->assertSame(['ID12345678901234567890'], $identifiers->ids());
        $this->assertTrue($identifiers->partial);

        $download = $parser->downloads(
            $this->soap(<<<'XML'
<eSocial xmlns="urn:return"><download>
  <status><cdResposta>201</cdResposta><descResposta>Sucesso</descResposta></status>
  <retornoSolicDownloadEvts><arquivos>
    <arquivo>
      <status><cdResposta>201</cdResposta><descResposta>Evento encontrado</descResposta></status>
      <evt Id="ID12345678901234567890"><eSocial xmlns="http://www.esocial.gov.br/schema/evt/evtFGTS/v_S_01_03_00"><evtFGTS><ideEvento><perApur>2026-06</perApur></ideEvento></evtFGTS></eSocial></evt>
    </arquivo>
    <arquivo><status><cdResposta>202</cdResposta><descResposta>Evento não encontrado</descResposta></status></arquivo>
  </arquivos></retornoSolicDownloadEvts>
</download></eSocial>
XML),
            EsocialEventCode::S5013,
            '2026-06',
            ['ID12345678901234567890' => '1.2.000000000000099'],
        );

        $this->assertTrue($download->partial);
        $this->assertCount(1, $download->events);
        $this->assertSame(EsocialEventCode::S5013, $download->events[0]->eventCode);
        $this->assertSame('1.2.000000000000099', $download->events[0]->receiptNumber);
        $this->assertSame('v_S_01_03_00', $download->events[0]->eventVersion);
    }

    public function test_parser_rejects_downloaded_event_type_mismatch(): void
    {
        $parser = new EsocialBxResponseParser;

        try {
            $parser->downloads(
                $this->soap(<<<'XML'
<eSocial xmlns="urn:return"><download>
  <status><cdResposta>201</cdResposta><descResposta>Sucesso</descResposta></status>
  <retornoSolicDownloadEvts><arquivos><arquivo>
    <status><cdResposta>201</cdResposta></status>
    <evt Id="ID12345678901234567890"><eSocial xmlns="urn:event"><evtFechaEvPer><ideEvento><perApur>2026-06</perApur></ideEvento></evtFechaEvPer></eSocial></evt>
  </arquivo></arquivos></retornoSolicDownloadEvts>
</download></eSocial>
XML),
                EsocialEventCode::S5013,
                '2026-06',
            );
            $this->fail('Tipo de evento divergente deveria falhar.');
        } catch (EsocialBxException $exception) {
            $this->assertSame('ESOCIAL_BX_EVENT_TYPE_MISMATCH', $exception->stableCode);
            $this->assertTrue($exception->blocked);
        }
    }

    /** @return array{string,string} */
    private function makePfx(): array
    {
        $password = 'test-only-password';
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'eSocial BX Test'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($certificate);
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, $password));

        return [$pfx, $password];
    }

    private function soap(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soap:Body>'.$body.'</soap:Body></soap:Envelope>';
    }
}
