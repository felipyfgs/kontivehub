<?php

declare(strict_types=1);

namespace Tests\Unit\Esocial;

use App\Contracts\EsocialBxCurlRuntime;
use App\DTO\Esocial\EsocialBxHttpResponse;
use App\Exceptions\EsocialBxException;
use App\Services\Esocial\CurlEsocialBxSoapTransport;
use App\Services\Esocial\EsocialBxConfig;
use Tests\TestCase;

class EsocialBxTransportTest extends TestCase
{
    public function test_transport_builds_locked_down_soap_11_mtls_options_in_memory(): void
    {
        $runtime = new CapturingEsocialBxCurlRuntime(new EsocialBxHttpResponse(200, '<soap/>', 'text/xml'));
        $transport = new CurlEsocialBxSoapTransport($runtime, app(EsocialBxConfig::class));
        $endpoint = app(EsocialBxConfig::class)->endpoint('restricted', 'identifiers');
        $action = 'http://www.esocial.gov.br/servicos/empregador/consulta/action';
        $password = 'test-secret-password';
        $pfx = 'binary-test-pfx';

        $response = $transport->post($endpoint, $action, '<soap>request</soap>', $pfx, $password);

        $this->assertSame(200, $response->status);
        $this->assertSame($endpoint, $runtime->endpoint);
        $options = $runtime->options;
        $this->assertTrue($options[CURLOPT_POST]);
        $this->assertSame('<soap>request</soap>', $options[CURLOPT_POSTFIELDS]);
        $this->assertTrue($options[CURLOPT_RETURNTRANSFER]);
        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(0, $options[CURLOPT_MAXREDIRS]);
        $this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        $this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame(CURL_SSLVERSION_TLSv1_2, $options[CURLOPT_SSLVERSION]);
        $this->assertSame(CURL_HTTP_VERSION_1_1, $options[CURLOPT_HTTP_VERSION]);
        $this->assertSame($pfx, $options[CURLOPT_SSLCERT_BLOB]);
        $this->assertSame('P12', $options[CURLOPT_SSLCERTTYPE]);
        $this->assertSame($password, $options[CURLOPT_KEYPASSWD]);
        $this->assertSame([
            'Content-Type: text/xml; charset=UTF-8',
            'SOAPAction: "'.$action.'"',
            'Content-Length: 20',
        ], $options[CURLOPT_HTTPHEADER]);
    }

    public function test_transport_rejects_non_official_endpoint_and_header_injection_before_runtime(): void
    {
        $runtime = new CapturingEsocialBxCurlRuntime(new EsocialBxHttpResponse(200, '<soap/>'));
        $transport = new CurlEsocialBxSoapTransport($runtime, app(EsocialBxConfig::class));

        try {
            $transport->post('https://attacker.example/soap', 'official-action', '<soap/>', 'pfx', 'secret');
            $this->fail('Endpoint não oficial deveria ser bloqueado.');
        } catch (EsocialBxException $exception) {
            $this->assertSame('ESOCIAL_BX_ENDPOINT_NOT_ALLOWED', $exception->stableCode);
            $this->assertTrue($exception->blocked);
            $this->assertSame([], $runtime->options);
        }

        try {
            $transport->post(
                app(EsocialBxConfig::class)->endpoint('restricted', 'downloads'),
                "official-action\r\nX-Injected: yes",
                '<soap/>',
                'pfx',
                'secret',
            );
            $this->fail('SOAPAction com quebra de linha deveria ser bloqueado.');
        } catch (EsocialBxException $exception) {
            $this->assertSame('ESOCIAL_BX_SOAP_ACTION_INVALID', $exception->stableCode);
            $this->assertTrue($exception->blocked);
            $this->assertSame([], $runtime->options);
        }
    }

    public function test_injected_runtime_exposes_http_status_and_keeps_tls_failure_sanitized(): void
    {
        $endpoint = app(EsocialBxConfig::class)->endpoint('restricted', 'downloads');
        $httpRuntime = new CapturingEsocialBxCurlRuntime(new EsocialBxHttpResponse(503, '<fault/>'));
        $http = new CurlEsocialBxSoapTransport($httpRuntime, app(EsocialBxConfig::class));
        $this->assertSame(503, $http->post($endpoint, 'official-action', '<soap/>', 'pfx', 'secret')->status);

        $tlsRuntime = new CapturingEsocialBxCurlRuntime(
            failure: new EsocialBxException(
                'ESOCIAL_BX_TRANSPORT_FAILED',
                'Falha de rede/TLS ao acessar eSocial BX.',
            ),
        );
        $tls = new CurlEsocialBxSoapTransport($tlsRuntime, app(EsocialBxConfig::class));

        try {
            $tls->post($endpoint, 'official-action', '<soap/>', 'pfx-secret-bytes', 'password-secret');
            $this->fail('Falha TLS injetada deveria propagar código sanitizado.');
        } catch (EsocialBxException $exception) {
            $encoded = json_encode($exception->toSanitizedArray(), JSON_THROW_ON_ERROR);
            $this->assertSame('ESOCIAL_BX_TRANSPORT_FAILED', $exception->stableCode);
            $this->assertStringNotContainsString('pfx-secret-bytes', $exception->getMessage().$encoded);
            $this->assertStringNotContainsString('password-secret', $exception->getMessage().$encoded);
        }
    }
}

final class CapturingEsocialBxCurlRuntime implements EsocialBxCurlRuntime
{
    public ?string $endpoint = null;

    /** @var array<int, mixed> */
    public array $options = [];

    public function __construct(
        private readonly ?EsocialBxHttpResponse $response = null,
        private readonly ?EsocialBxException $failure = null,
    ) {}

    public function execute(string $endpoint, array $options): EsocialBxHttpResponse
    {
        $this->endpoint = $endpoint;
        $this->options = $options;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->response ?? new EsocialBxHttpResponse(200, '<soap/>');
    }
}
