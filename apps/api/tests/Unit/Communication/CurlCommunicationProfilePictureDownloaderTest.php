<?php

namespace Tests\Unit\Communication;

use App\Exceptions\CommunicationProfilePictureDownloadException;
use App\Services\Communication\ProfilePicture\CurlCommunicationProfilePictureDownloader;
use Tests\TestCase;

final class CurlCommunicationProfilePictureDownloaderTest extends TestCase
{
    public function test_rejects_non_https_and_non_allowlisted_urls_before_network(): void
    {
        $downloader = app(CurlCommunicationProfilePictureDownloader::class);

        foreach (['http://media.whatsapp.net/a.jpg', 'https://other.example.test/a.jpg', 'https://media.whatsapp.net:444/a.jpg', 'https://user:password@media.whatsapp.net/a.jpg', 'https://media.whatsapp.net./a.jpg'] as $url) {
            try {
                $downloader->download($url);
                self::fail('A URL deveria ser rejeitada.');
            } catch (CommunicationProfilePictureDownloadException $error) {
                self::assertSame('PROFILE_PICTURE_URL_REJECTED', $error->safeCode);
            }
        }
    }

    public function test_rejects_documentation_cgnat_multicast_and_mapped_ips(): void
    {
        foreach (['100.64.0.1', '192.0.2.1', '192.88.99.1', '224.0.0.1', '2001:db8::1', '::ffff:192.0.2.1', '::192.0.2.1', '64:ff9b::c000:201', 'fe9f::1', 'fec0::1', '4000::1'] as $ip) {
            self::assertFalse(CurlCommunicationProfilePictureDownloader::isPublicIp($ip));
        }
        self::assertTrue(CurlCommunicationProfilePictureDownloader::isPublicIp('8.8.8.8'));
        self::assertTrue(CurlCommunicationProfilePictureDownloader::isPublicIp('2606:4700:4700::1111'));
    }

    public function test_rejects_mixed_dns_before_curl_and_pins_public_ipv4_without_proxy_or_redirects(): void
    {
        $executed = false;
        $mixed = new CurlCommunicationProfilePictureDownloader(
            static fn (): array => [['ip' => '8.8.8.8'], ['ipv6' => 'fe80::1']],
            static function () use (&$executed): array {
                $executed = true;

                return [];
            },
        );
        try {
            $mixed->download('https://media.whatsapp.net/avatar.png');
            self::fail('DNS misto deveria falhar fechado.');
        } catch (CommunicationProfilePictureDownloadException $error) {
            self::assertSame('PROFILE_PICTURE_DNS_REJECTED', $error->safeCode);
        }
        self::assertFalse($executed);

        $captured = [];
        $png = $this->png();
        $downloader = $this->fakeDownloader(
            [['ip' => '8.8.8.8']],
            ['ok' => true, 'status' => 200, 'primary_ip' => '8.8.8.8', 'mime' => 'image/png', 'errno' => 0],
            $png,
            $captured,
        );
        $download = $downloader->download('https://media.whatsapp.net/avatar.png');
        self::assertSame('image/png', $download->mimeType);
        self::assertSame(strlen($png), $download->sizeBytes);
        self::assertSame('', $captured[CURLOPT_PROXY]);
        self::assertSame('*', $captured[CURLOPT_NOPROXY]);
        self::assertFalse($captured[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $captured[CURLOPT_MAXREDIRS]);
        self::assertSame(CURLPROTO_HTTPS, $captured[CURLOPT_PROTOCOLS]);
        self::assertSame(['media.whatsapp.net:443:8.8.8.8'], $captured[CURLOPT_RESOLVE]);
        fclose($download->stream);
    }

    public function test_formats_ipv6_pin_and_rejects_primary_ip_rebinding(): void
    {
        $captured = [];
        $downloader = $this->fakeDownloader(
            [['ipv6' => '2606:4700:4700::1111']],
            ['ok' => true, 'status' => 200, 'primary_ip' => '2606:4700:4700::1111', 'mime' => 'image/png', 'errno' => 0],
            $this->png(),
            $captured,
        );
        $download = $downloader->download('https://media.whatsapp.net/avatar.png');
        self::assertSame(['media.whatsapp.net:443:[2606:4700:4700::1111]'], $captured[CURLOPT_RESOLVE]);
        fclose($download->stream);

        $rebound = $this->fakeDownloader(
            [['ip' => '8.8.8.8'], ['ip' => '1.1.1.1']],
            ['ok' => true, 'status' => 200, 'primary_ip' => '1.1.1.1', 'mime' => 'image/png', 'errno' => 0],
            $this->png(),
        );
        try {
            $rebound->download('https://media.whatsapp.net/avatar.png');
            self::fail('IP primário divergente deveria ser rejeitado.');
        } catch (CommunicationProfilePictureDownloadException $error) {
            self::assertSame('PROFILE_PICTURE_DOWNLOAD_REJECTED', $error->safeCode);
            self::assertFalse($error->retryable);
        }
    }

    public function test_classifies_redirect_not_found_timeout_and_size_without_egress(): void
    {
        $cases = [
            'redirect' => [302, 0, 'PROFILE_PICTURE_DOWNLOAD_REJECTED', false],
            'not-found' => [404, 0, 'PROFILE_PICTURE_NOT_FOUND', false],
            'forbidden' => [403, 0, 'PROFILE_PICTURE_DOWNLOAD_REJECTED', false],
            'timeout' => [0, CURLE_OPERATION_TIMEDOUT, 'PROFILE_PICTURE_DOWNLOAD_TRANSIENT', true],
            'rate-limit' => [429, 0, 'PROFILE_PICTURE_DOWNLOAD_TRANSIENT', true],
        ];
        foreach ($cases as $case => [$status, $errno, $code, $retryable]) {
            $downloader = $this->fakeDownloader(
                [['ip' => '8.8.8.8']],
                ['ok' => false, 'status' => $status, 'primary_ip' => '8.8.8.8', 'mime' => 'text/plain', 'errno' => $errno],
                '',
            );
            try {
                $downloader->download('https://media.whatsapp.net/'.$case);
                self::fail('Resposta inválida deveria falhar.');
            } catch (CommunicationProfilePictureDownloadException $error) {
                self::assertSame($code, $error->safeCode);
                self::assertSame($retryable, $error->retryable);
                self::assertSame($status ?: null, $error->httpStatus);
            }
        }

        config(['communication.profile_pictures.max_bytes' => 8]);
        $oversized = $this->fakeDownloader(
            [['ip' => '8.8.8.8']],
            ['ok' => false, 'status' => 200, 'primary_ip' => '8.8.8.8', 'mime' => 'image/png', 'errno' => CURLE_WRITE_ERROR],
            str_repeat('x', 9),
        );
        try {
            $oversized->download('https://media.whatsapp.net/large.png');
            self::fail('Imagem acima do limite deveria ser rejeitada.');
        } catch (CommunicationProfilePictureDownloadException $error) {
            self::assertSame('PROFILE_PICTURE_DOWNLOAD_REJECTED', $error->safeCode);
            self::assertFalse($error->retryable);
        }
    }

    public function test_rejects_mime_signature_and_dimensions_that_do_not_match_policy(): void
    {

        foreach ([
            ['image/jpeg', $this->png()],
            ['image/png', 'not-an-image'],
            ['text/plain', $this->png()],
        ] as [$mime, $body]) {
            $downloader = $this->fakeDownloader(
                [['ip' => '8.8.8.8']],
                ['ok' => true, 'status' => 200, 'primary_ip' => '8.8.8.8', 'mime' => $mime, 'errno' => 0],
                $body,
            );
            try {
                $downloader->download('https://media.whatsapp.net/avatar');
                self::fail('Conteúdo incoerente deveria ser rejeitado.');
            } catch (CommunicationProfilePictureDownloadException $error) {
                self::assertSame('PROFILE_PICTURE_DOWNLOAD_REJECTED', $error->safeCode);
                self::assertFalse($error->retryable);
            }
        }

        config(['communication.profile_pictures.max_dimension' => 0]);
        $oversizedDimensions = $this->fakeDownloader(
            [['ip' => '8.8.8.8']],
            ['ok' => true, 'status' => 200, 'primary_ip' => '8.8.8.8', 'mime' => 'image/png', 'errno' => 0],
            $this->png(),
        );
        try {
            $oversizedDimensions->download('https://media.whatsapp.net/avatar.png');
            self::fail('Dimensão acima do limite deveria ser rejeitada.');
        } catch (CommunicationProfilePictureDownloadException $error) {
            self::assertSame('PROFILE_PICTURE_DOWNLOAD_REJECTED', $error->safeCode);
            self::assertFalse($error->retryable);
        }
    }

    /**
     * @param  list<array<string,string>>  $records
     * @param  array{ok:bool,status:int,primary_ip:string,mime:string,errno:int}  $result
     * @param  array<int,mixed>  $captured
     */
    private function fakeDownloader(array $records, array $result, string $body, array &$captured = []): CurlCommunicationProfilePictureDownloader
    {
        return new CurlCommunicationProfilePictureDownloader(
            static fn (): array => $records,
            static function (string $url, array $options) use ($result, $body, &$captured): array {
                $captured = $options;
                if ($body !== '') {
                    $options[CURLOPT_WRITEFUNCTION](null, $body);
                }

                return $result;
            },
        );
    }

    private function png(): string
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($bytes);

        return $bytes;
    }
}
