<?php

namespace App\Services\Communication;

use App\Models\CommunicationInbox;
use InvalidArgumentException;

/**
 * Resolve o peer 1:1 de um evento de gateway.
 *
 * Alinhado a GOWA/Chatwoot (chave = Chat) e Evolution (LID/PN como aliases):
 * - o peer é o chat, nunca o device da sessão;
 * - source_identity.primary representa Chat e tem precedência sobre `from`;
 * - primary LID + alternate PN remota promove o PN canônico em qualquer direção;
 * - a PN da própria sessão nunca é peer nem alias do contato remoto.
 */
final class WhatsappPeerResolver
{
    public function __construct(
        private WhatsappAddressNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolve(array $payload, ?CommunicationInbox $inbox = null): string
    {
        $sessionAddress = $this->sessionAddress($inbox);
        $from = $this->optionalNormalize((string) ($payload['from'] ?? ''));
        $identity = is_array($payload['source_identity'] ?? null) ? $payload['source_identity'] : [];
        $primary = $this->optionalNormalize((string) ($identity['primary'] ?? ''));
        $alternate = $this->optionalNormalize((string) ($identity['alternate'] ?? ''));
        $primaryKind = strtoupper(trim((string) ($identity['primary_kind'] ?? '')));
        $alternateKind = strtoupper(trim((string) ($identity['alternate_kind'] ?? '')));

        $hasStructuredIdentity = $primary !== null || $alternate !== null;
        $chat = $hasStructuredIdentity ? ($primary ?? $alternate) : $from;
        if ($chat === null) {
            throw new InvalidArgumentException('Endereço do peer ausente no evento do gateway.');
        }

        $peer = $chat;
        if ($primary !== null && $this->isLid($primary)
            && $alternate !== null && $this->isPn($alternate)
            && ! $this->sameAddress($alternate, $sessionAddress)) {
            $peer = $alternate;
        }

        // Fail-closed: peer não pode ser a própria sessão.
        if ($this->sameAddress($peer, $sessionAddress)) {
            if ($primary !== null && ! $this->sameAddress($primary, $sessionAddress)) {
                $peer = $primary;
            } elseif (! $hasStructuredIdentity && $from !== null
                && ! $this->sameAddress($from, $sessionAddress)) {
                $peer = $from;
            } else {
                throw new InvalidArgumentException('Peer do evento coincide com a sessão WhatsApp (self-chat).');
            }
        }

        if ($this->sameAddress($peer, $sessionAddress)) {
            throw new InvalidArgumentException('Peer do evento coincide com a sessão WhatsApp (self-chat).');
        }

        unset($primaryKind, $alternateKind);

        return $peer;
    }

    /**
     * Endereços alias (LID/PN) do mesmo peer para eventual unificação de contato.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function aliases(
        array $payload,
        string $canonicalPeer,
        ?CommunicationInbox $inbox = null,
    ): array
    {
        $sessionAddress = $this->sessionAddress($inbox);
        $aliases = [$canonicalPeer];
        $identity = is_array($payload['source_identity'] ?? null) ? $payload['source_identity'] : [];
        $structuredCandidates = [$identity['primary'] ?? null, $identity['alternate'] ?? null];
        $candidates = array_filter(
            $structuredCandidates,
            static fn (mixed $candidate): bool => is_string($candidate) && trim($candidate) !== '',
        ) !== []
            ? $structuredCandidates
            : [$payload['from'] ?? null];

        foreach ($candidates as $candidate) {
            $normalized = $this->optionalNormalize(is_string($candidate) ? $candidate : '');
            if ($normalized !== null
                && ! $this->sameAddress($normalized, $sessionAddress)
                && ! in_array($normalized, $aliases, true)) {
                $aliases[] = $normalized;
            }
        }

        return array_values(array_filter(
            $aliases,
            fn (string $alias): bool => ! $this->sameAddress($alias, $sessionAddress),
        ));
    }

    private function sessionAddress(?CommunicationInbox $inbox): ?string
    {
        if ($inbox === null) {
            return null;
        }
        $raw = trim((string) ($inbox->address_encrypted ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return $this->normalizer->normalize($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function optionalNormalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        try {
            return $this->normalizer->normalize($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function isLid(string $address): bool
    {
        return str_starts_with($address, 'lid:');
    }

    private function isPn(string $address): bool
    {
        return str_starts_with($address, '+');
    }

    private function sameAddress(?string $left, ?string $right): bool
    {
        return $left !== null && $right !== null && hash_equals($left, $right);
    }
}
