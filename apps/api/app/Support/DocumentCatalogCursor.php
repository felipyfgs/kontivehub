<?php

namespace App\Support;

use App\Enums\DocumentKind;
use InvalidArgumentException;

/**
 * Cursor opaco com posição independente por projeção do catálogo.
 *
 * IDs de NFS-e, NF-e, CT-e e MDF-e pertencem a sequências diferentes; tratá-los como
 * um único número faz a paginação repetir ou pular documentos.
 */
final readonly class DocumentCatalogCursor
{
    public function __construct(
        public ?int $nfseBeforeId = null,
        public ?int $sefazBeforeId = null,
        public ?int $cteBeforeId = null,
        public ?int $mdfeBeforeId = null,
    ) {}

    public static function fromToken(?string $token): self
    {
        if ($token === null || $token === '') {
            return new self;
        }

        // Compatibilidade com o cursor escalar emitido antes do catálogo multi-fonte.
        if (ctype_digit($token) && (int) $token > 0) {
            return new self((int) $token, (int) $token, (int) $token, (int) $token);
        }

        $padding = (4 - strlen($token) % 4) % 4;
        $decoded = base64_decode(strtr($token.str_repeat('=', $padding), '-_', '+/'), true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;

        if (! is_array($payload) || ! in_array($payload['v'] ?? null, [1, 2, 3], true)) {
            throw new InvalidArgumentException('Cursor do catálogo inválido.');
        }

        return new self(
            self::nullablePositiveInt($payload['nfse'] ?? null),
            self::nullablePositiveInt($payload['sefaz'] ?? null),
            self::nullablePositiveInt($payload['cte'] ?? null),
            self::nullablePositiveInt($payload['mdfe'] ?? null),
        );
    }

    public function beforeId(DocumentKind $kind): ?int
    {
        return match ($kind) {
            DocumentKind::Nfse => $this->nfseBeforeId,
            DocumentKind::Cte => $this->cteBeforeId,
            DocumentKind::Mdfe => $this->mdfeBeforeId,
            default => $this->sefazBeforeId,
        };
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function advance(iterable $rows): self
    {
        $nfse = $this->nfseBeforeId;
        $sefaz = $this->sefazBeforeId;
        $cte = $this->cteBeforeId;
        $mdfe = $this->mdfeBeforeId;

        foreach ($rows as $row) {
            $id = self::nullablePositiveInt($row['id'] ?? null);
            if ($id === null) {
                continue;
            }

            $kind = $row['kind'] ?? null;
            if ($kind === DocumentKind::Nfse->value) {
                $nfse = $id;
            } elseif ($kind === DocumentKind::Cte->value) {
                $cte = $id;
            } elseif ($kind === DocumentKind::Mdfe->value) {
                $mdfe = $id;
            } else {
                $sefaz = $id;
            }
        }

        return new self($nfse, $sefaz, $cte, $mdfe);
    }

    public function toToken(): string
    {
        $json = json_encode([
            'v' => 3,
            'nfse' => $this->nfseBeforeId,
            'sefaz' => $this->sefazBeforeId,
            'cte' => $this->cteBeforeId,
            'mdfe' => $this->mdfeBeforeId,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException('Cursor do catálogo inválido.');
        }

        $id = (int) $value;
        if ($id < 1) {
            throw new InvalidArgumentException('Cursor do catálogo inválido.');
        }

        return $id;
    }
}
