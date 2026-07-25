<?php

namespace App\Services\Esocial;

use App\DTO\Esocial\EsocialBxDownloadResult;
use App\DTO\Esocial\EsocialBxIdentifier;
use App\DTO\Esocial\EsocialBxIdentifiersResult;
use App\DTO\Esocial\EsocialEventDto;
use App\Enums\EsocialEventCode;
use App\Exceptions\EsocialBxException;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

final class EsocialBxResponseParser
{
    public function identifiers(string $xml): EsocialBxIdentifiersResult
    {
        [$dom, $xpath] = $this->document($xml);
        $this->assertNoSoapFault($xpath);
        [$code] = $this->officialStatus($xpath);
        if ($code === '406') {
            return new EsocialBxIdentifiersResult([], false, $code);
        }
        $this->assertOfficialSuccess($code, allowPartial: true);

        $ids = [];
        $seen = [];
        foreach ($xpath->query('//*[local-name()="identificadorEvt"]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $id = $this->childText($node, 'id');
            if ($id === null || ! preg_match('/^ID[0-9A-Za-z_-]{10,80}$/', $id)) {
                throw new EsocialBxException(
                    'ESOCIAL_BX_IDENTIFIER_INVALID',
                    'Resposta eSocial BX contém identificador inválido.',
                );
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = new EsocialBxIdentifier($id, $this->childText($node, 'nrRec'));
        }

        $total = (int) ($this->firstText($xpath, '//*[local-name()="qtdeTotEvtsConsulta"]') ?? count($ids));

        $returned = array_slice($ids, 0, 50);

        return new EsocialBxIdentifiersResult(
            $returned,
            $code === '203' || $total > count($returned) || count($ids) > 50,
            $code,
        );
    }

    /** @param array<string, string|null> $receiptsById
     */
    public function downloads(
        string $xml,
        EsocialEventCode $expectedCode,
        string $expectedCompetence,
        array $receiptsById = [],
    ): EsocialBxDownloadResult {
        [$dom, $xpath] = $this->document($xml);
        $this->assertNoSoapFault($xpath);
        [$code] = $this->officialStatus($xpath);
        $this->assertOfficialSuccess($code);

        $events = [];
        $partial = false;
        foreach ($xpath->query('//*[local-name()="arquivo"]') ?: [] as $file) {
            if (! $file instanceof DOMElement) {
                continue;
            }
            $fileCode = $this->firstText(
                $xpath,
                './*[local-name()="status"]/*[local-name()="cdResposta"]',
                $file,
            );
            if ($fileCode !== '201') {
                $partial = true;

                continue;
            }

            $evt = $this->directChild($file, 'evt');
            if (! $evt instanceof DOMElement) {
                $partial = true;

                continue;
            }
            $id = trim($evt->getAttribute('Id'));
            $payloadNode = $this->firstElementChild($evt);
            if ($payloadNode === null) {
                $partial = true;

                continue;
            }
            $payload = $dom->saveXML($payloadNode);
            if (! is_string($payload) || $payload === '') {
                $partial = true;

                continue;
            }

            $this->assertEventMatches($payload, $expectedCode, $expectedCompetence);
            $rec = $this->directChild($file, 'rec');
            $receipt = $rec?->getAttribute('nrRec') ?: ($receiptsById[$id] ?? null);
            $events[] = new EsocialEventDto(
                eventCode: $expectedCode,
                competencePeriodKey: $expectedCompetence,
                payloadBytes: $payload,
                eventVersion: $this->namespaceVersion($payloadNode->namespaceURI),
                receiptNumber: is_string($receipt) && $receipt !== '' ? $receipt : null,
                observedAt: CarbonImmutable::now(),
                metadata: [
                    'source' => 'ESOCIAL_BX_OFFICIAL',
                    'event_id_hash' => $id === '' ? null : hash('sha256', $id),
                ],
            );
        }

        return new EsocialBxDownloadResult($events, $partial, $code);
    }

    /** @return array{DOMDocument, DOMXPath} */
    private function document(string $xml): array
    {
        if ($xml === '' || strlen($xml) > 20 * 1024 * 1024) {
            throw new EsocialBxException('ESOCIAL_BX_RESPONSE_INVALID', 'Resposta eSocial BX vazia ou acima do limite.', true);
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded || $dom->documentElement === null) {
            throw new EsocialBxException('ESOCIAL_BX_RESPONSE_MALFORMED', 'Resposta XML inválida do eSocial BX.', true);
        }

        return [$dom, new DOMXPath($dom)];
    }

    private function assertNoSoapFault(DOMXPath $xpath): void
    {
        $fault = $xpath->query('//*[local-name()="Fault"]')->item(0);
        if ($fault !== null) {
            throw new EsocialBxException(
                'ESOCIAL_BX_SOAP_FAULT',
                'O eSocial BX retornou uma falha SOAP.',
                retryable: true,
            );
        }
    }

    /** @return array{string,string} */
    private function officialStatus(DOMXPath $xpath): array
    {
        $statuses = $xpath->query('//*[local-name()="status"]');
        if ($statuses === false || $statuses->length === 0) {
            throw new EsocialBxException('ESOCIAL_BX_STATUS_MISSING', 'Resposta eSocial BX sem status oficial.', true);
        }
        $status = $statuses->item(0);
        if (! $status instanceof DOMElement) {
            throw new EsocialBxException('ESOCIAL_BX_STATUS_MISSING', 'Resposta eSocial BX sem status oficial.', true);
        }

        return [
            $this->childText($status, 'cdResposta') ?? '',
            $this->childText($status, 'descResposta') ?? '',
        ];
    }

    private function assertOfficialSuccess(string $code, bool $allowPartial = false): void
    {
        if ($code === '201' || ($allowPartial && $code === '203')) {
            return;
        }

        $retryable = in_array($code, ['301', '307', '308', '309', '310', '404'], true);
        $blocked = in_array($code, ['403', '405', '407', '411'], true);
        $stable = match ($code) {
            '301', '307', '308', '309', '310' => 'ESOCIAL_BX_OFFICIAL_TEMPORARY',
            '402', '408', '417' => 'ESOCIAL_BX_REQUEST_REJECTED',
            '403' => 'ESOCIAL_BX_BLOCKED_WINDOW',
            '405' => 'ESOCIAL_BX_QUOTA_EXHAUSTED',
            '407' => 'ESOCIAL_BX_AUTHORIZATION_DENIED',
            '409' => 'ESOCIAL_BX_MINIMUM_LAG',
            '410' => 'ESOCIAL_BX_INTERVAL_LIMIT',
            '411' => 'ESOCIAL_BX_CERTIFICATE_MISMATCH',
            '404' => 'ESOCIAL_BX_CONCURRENT_REQUEST',
            default => 'ESOCIAL_BX_OFFICIAL_REJECTION',
        };

        throw new EsocialBxException(
            $stable,
            'Solicitação rejeitada pelo eSocial BX.',
            retryable: $retryable,
            blocked: $blocked,
            officialCode: $code === '' ? null : $code,
        );
    }

    private function assertEventMatches(string $payload, EsocialEventCode $expectedCode, string $expectedCompetence): void
    {
        [$dom, $xpath] = $this->document($payload);
        $expectedElement = match ($expectedCode) {
            EsocialEventCode::S1299 => 'evtFechaEvPer',
            EsocialEventCode::S5013 => 'evtFGTS',
            EsocialEventCode::S5003 => 'evtBasesTrab',
        };
        if (($xpath->query('//*[local-name()="'.$expectedElement.'"]')->length ?? 0) === 0) {
            throw new EsocialBxException(
                'ESOCIAL_BX_EVENT_TYPE_MISMATCH',
                'Arquivo baixado diverge do tipo de evento solicitado.',
                blocked: true,
            );
        }
        $competence = $this->firstText($xpath, '//*[local-name()="perApur"]');
        if ($competence !== $expectedCompetence) {
            throw new EsocialBxException(
                'ESOCIAL_BX_EVENT_COMPETENCE_MISMATCH',
                'Arquivo baixado diverge da competência solicitada.',
                blocked: true,
            );
        }
    }

    private function childText(DOMElement $parent, string $localName): ?string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return trim($child->textContent);
            }
        }

        return null;
    }

    private function directChild(DOMElement $parent, string $localName): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return $child;
            }
        }

        return null;
    }

    private function firstElementChild(DOMElement $parent): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child;
            }
        }

        return null;
    }

    private function firstText(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?string
    {
        $value = $xpath->query($query, $context)?->item(0)?->textContent;

        return is_string($value) ? trim($value) : null;
    }

    private function namespaceVersion(?string $namespace): ?string
    {
        return is_string($namespace) && preg_match('~/([^/]+)$~', $namespace, $matches)
            ? $matches[1]
            : null;
    }
}
