<?php

namespace App\Services\Communication\Canned;

use App\Models\CommunicationCannedResponse;
use App\Models\CommunicationConversation;
use App\Models\User;
use App\Support\CurrentOffice;
use InvalidArgumentException;

final class CannedResponseRenderer
{
    /** @var list<string> */
    public const ALLOWED_PLACEHOLDERS = [
        'contato.nome',
        'cliente.nome',
        'atendente.nome',
        'inbox.nome',
    ];

    public function __construct(
        private readonly CurrentOffice $currentOffice,
    ) {}

    /**
     * @return list<string> tokens found outside the allowlist (normalized)
     */
    public static function disallowedPlaceholders(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/iu', $body, $matches);
        $found = [];
        foreach ($matches[1] as $token) {
            $normalized = mb_strtolower(trim((string) $token));
            if ($normalized === '' || in_array($normalized, self::ALLOWED_PLACEHOLDERS, true)) {
                continue;
            }
            $found[$normalized] = $normalized;
        }

        return array_values($found);
    }

    public function assertBodyPlaceholdersAllowed(string $body): void
    {
        $disallowed = self::disallowedPlaceholders($body);
        if ($disallowed === []) {
            return;
        }

        throw new InvalidArgumentException(
            'Placeholders não permitidos: '.implode(', ', array_map(
                static fn (string $token): string => '{{'.$token.'}}',
                $disallowed,
            )),
        );
    }

    public function render(
        CommunicationCannedResponse $canned,
        CommunicationConversation $conversation,
        User $actor,
    ): string {
        $office = $this->currentOffice->office();
        if ((int) $canned->office_id !== (int) $office->id
            || (int) $conversation->office_id !== (int) $office->id) {
            throw new InvalidArgumentException('cross_office');
        }

        $conversation->loadMissing([
            'inbox',
            'identity.contact',
            'identity.clientLinks.client',
            'clients',
        ]);

        $map = [
            'contato.nome' => (string) ($conversation->identity?->contact?->name ?? ''),
            'cliente.nome' => $this->resolveClientName($conversation),
            'atendente.nome' => (string) ($actor->name ?? ''),
            'inbox.nome' => (string) ($conversation->inbox?->name ?? ''),
        ];

        $body = (string) $canned->body_encrypted;

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_.]+)\s*\}\}/iu',
            static function (array $match) use ($map): string {
                $key = mb_strtolower(trim((string) $match[1]));
                if (! array_key_exists($key, $map)) {
                    return $match[0];
                }

                return $map[$key];
            },
            $body,
        );
    }

    private function resolveClientName(CommunicationConversation $conversation): string
    {
        $primaryLink = $conversation->identity?->clientLinks
            ?->sortByDesc(static fn ($link): int => (int) ($link->is_primary ? 1 : 0))
            ->first();
        if ($primaryLink?->client !== null) {
            return $primaryLink->client->displayLabel();
        }

        $firstClient = $conversation->clients->first();

        return $firstClient !== null ? $firstClient->displayLabel() : '';
    }
}
