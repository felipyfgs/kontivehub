<?php

namespace App\Services\Communication\ProfilePicture;

use App\Contracts\CommunicationProfilePictureDownloader;
use App\DTO\Communication\DownloadedProfilePicture;
use App\Exceptions\CommunicationProfilePictureDownloadException;
use Closure;
use Throwable;

final class CurlCommunicationProfilePictureDownloader implements CommunicationProfilePictureDownloader
{
    /**
     * Test seams receive no credentials and must return only allowlisted metadata.
     *
     * @param  (Closure(string): list<array<string,string>>)|null  $dnsResolver
     * @param  (Closure(string,array<int,mixed>): array{ok:bool,status:int,primary_ip:string,mime:string,errno:int})|null  $curlExecutor
     */
    public function __construct(
        private readonly ?Closure $dnsResolver = null,
        private readonly ?Closure $curlExecutor = null,
    ) {}

    public function download(string $url): DownloadedProfilePicture
    {
        $parts = parse_url($url);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : null;
        if (! is_array($parts) || array_key_exists('user', $parts) || array_key_exists('pass', $parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['port'] ?? 443) !== 443 || $host === null || str_ends_with($host, '.') || ! self::isWhatsappHost($host)) {
            throw new CommunicationProfilePictureDownloadException('PROFILE_PICTURE_URL_REJECTED');
        }
        $records = $this->resolveDns($host);
        $ips = array_values(array_unique(array_merge(array_column($records, 'ip'), array_column($records, 'ipv6'))));
        if ($ips === [] || count($ips) !== count(array_filter($ips, fn ($ip) => is_string($ip) && self::isPublicIp($ip)))) {
            throw new CommunicationProfilePictureDownloadException('PROFILE_PICTURE_DNS_REJECTED');
        }
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (! is_resource($stream)) {
            throw new CommunicationProfilePictureDownloadException('PROFILE_PICTURE_TEMPORARY_STORAGE_FAILED', true);
        }
        $max = (int) config('communication.profile_pictures.max_bytes', 2_097_152);
        $written = 0;
        $tooLarge = false;
        $resolveIp = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];
        $options = [CURLOPT_PROXY => '', CURLOPT_PROXYTYPE => CURLPROXY_HTTP, CURLOPT_NOPROXY => '*', CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($stream, $max, &$written, &$tooLarge): int {
            $next = $written + strlen($chunk);
            if ($next > $max) {
                $tooLarge = true;

                return 0;
            }
            $written = $next;

            return fwrite($stream, $chunk) ?: 0;
        }, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, CURLOPT_CONNECTTIMEOUT => (int) config('communication.profile_pictures.connect_timeout_seconds', 5), CURLOPT_TIMEOUT => (int) config('communication.profile_pictures.timeout_seconds', 15), CURLOPT_USERPWD => null, CURLOPT_HTTPAUTH => CURLAUTH_NONE, CURLOPT_UNRESTRICTED_AUTH => false, CURLOPT_COOKIESESSION => true, CURLOPT_NOBODY => false, CURLOPT_ENCODING => 'identity', CURLOPT_HTTPHEADER => ['Accept: image/jpeg,image/png,image/webp'], CURLOPT_RESOLVE => [$host.':443:'.$resolveIp]];
        try {
            $transfer = $this->executeCurl($url, $options);
        } catch (CommunicationProfilePictureDownloadException $error) {
            fclose($stream);
            throw $error;
        } catch (Throwable) {
            fclose($stream);
            throw new CommunicationProfilePictureDownloadException('PROFILE_PICTURE_DOWNLOAD_TRANSIENT', true);
        }
        $ok = $transfer['ok'];
        $status = $transfer['status'];
        $primaryIp = $transfer['primary_ip'];
        $mime = strtolower(trim($transfer['mime']));
        $errno = $transfer['errno'];
        $size = ftell($stream);
        rewind($stream);
        $head = (string) fread($stream, 32);
        rewind($stream);
        $allowed = ['image/jpeg' => "\xFF\xD8\xFF", 'image/png' => "\x89PNG\r\n\x1A\n", 'image/webp' => 'RIFF'];
        $mime = explode(';', $mime)[0];
        $contents = stream_get_contents($stream);
        rewind($stream);
        $dimensions = is_string($contents) ? @getimagesizefromstring($contents) : false;
        $expectedType = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];
        $maxDimension = (int) config('communication.profile_pictures.max_dimension', 4_096);
        $primaryPacked = @inet_pton($primaryIp);
        $chosenPacked = inet_pton($ips[0]);
        $pinned = is_string($primaryPacked) && $primaryPacked === $chosenPacked;
        if ($ok !== true || $status !== 200 || ! $pinned || $size < 1 || $size > $max || ! isset($allowed[$mime]) || ! str_starts_with($head, $allowed[$mime]) || ! is_array($dimensions) || ($dimensions[2] ?? null) !== $expectedType[$mime] || ! is_int($dimensions[0] ?? null) || ! is_int($dimensions[1] ?? null) || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > $maxDimension || $dimensions[1] > $maxDimension) {
            fclose($stream);
            $retryable = ! $tooLarge && ($errno !== 0 || $status === 0 || in_array($status, [408, 425, 429], true) || $status >= 500);
            $safe = $status === 404 ? 'PROFILE_PICTURE_NOT_FOUND' : ($retryable ? 'PROFILE_PICTURE_DOWNLOAD_TRANSIENT' : 'PROFILE_PICTURE_DOWNLOAD_REJECTED');
            throw new CommunicationProfilePictureDownloadException($safe, $retryable, $status ?: null);
        }

        return new DownloadedProfilePicture($stream, $mime, $size);
    }

    /** @return list<array<string, string>> */
    private function resolveDns(string $host): array
    {
        if ($this->dnsResolver !== null) {
            $records = ($this->dnsResolver)($host);

            return is_array($records) ? $records : [];
        }

        return dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
    }

    /**
     * @param  array<int,mixed>  $options
     * @return array{ok:bool,status:int,primary_ip:string,mime:string,errno:int}
     */
    private function executeCurl(string $url, array $options): array
    {
        if ($this->curlExecutor !== null) {
            $result = ($this->curlExecutor)($url, $options);
            if (! is_array($result)
                || ! is_bool($result['ok'] ?? null)
                || ! is_int($result['status'] ?? null)
                || ! is_string($result['primary_ip'] ?? null)
                || ! is_string($result['mime'] ?? null)
                || ! is_int($result['errno'] ?? null)) {
                throw new CommunicationProfilePictureDownloadException('PROFILE_PICTURE_DOWNLOAD_TRANSIENT', true);
            }

            return $result;
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new CommunicationProfilePictureDownloadException('PROFILE_PICTURE_DOWNLOAD_TRANSIENT', true);
        }
        try {
            if (! curl_setopt_array($curl, $options)) {
                throw new CommunicationProfilePictureDownloadException('PROFILE_PICTURE_DOWNLOAD_TRANSIENT', true);
            }
            $ok = curl_exec($curl);

            return [
                'ok' => $ok === true,
                'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                'primary_ip' => (string) curl_getinfo($curl, CURLINFO_PRIMARY_IP),
                'mime' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE),
                'errno' => curl_errno($curl),
            ];
        } finally {
            curl_close($curl);
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16 && ! self::inCidr($packed, '2000::/3')) {
            return false;
        }
        $cidrs = strlen($packed) === 4 ? ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] : ['::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20', '5f00::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8'];
        foreach ($cidrs as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function isWhatsappHost(string $host): bool
    {
        return $host === 'whatsapp.net' || str_ends_with($host, '.whatsapp.net');
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $base = inet_pton($network);
        $bits = (int) $bits;
        if ($base === false || strlen($base) !== strlen($ip)) {
            return false;
        }
        $whole = intdiv($bits, 8);
        $rest = $bits % 8;
        if ($whole > 0 && substr($ip, 0, $whole) !== substr($base, 0, $whole)) {
            return false;
        }

        return $rest === 0 || (ord($ip[$whole]) & (0xFF << (8 - $rest))) === (ord($base[$whole]) & (0xFF << (8 - $rest)));
    }
}
