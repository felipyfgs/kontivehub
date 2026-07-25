<?php

namespace App\Services\Certificates;

use App\Contracts\PfxReaderInterface;
use App\Contracts\SecureObjectStore;
use App\Domain\Cnpj;
use App\Enums\CredentialStatus;
use App\Enums\SyncCursorStatus;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\SyncCursor;
use App\Support\CurrentOffice;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CredentialService
{
    public function __construct(
        private readonly SecureObjectStore $store,
        private readonly PfxReaderInterface $pfxReader,
        private readonly CurrentOffice $currentOffice,
    ) {}

    public function activate(Client $client, string $pfxBinary, string $password): ClientCredential
    {
        $meta = $this->pfxReader->read($pfxBinary, $password);
        $holder = Cnpj::parse($meta['cnpj']);

        if ($holder->root() !== $client->root_cnpj) {
            throw new RuntimeException('A raiz do CNPJ do certificado diverge da raiz do cliente.');
        }

        $officeId = $this->currentOffice->office()->id;
        if ($client->office_id !== $officeId) {
            abort(404);
        }

        $payload = json_encode([
            'pfx' => base64_encode($meta['pfx']),
            'password' => $meta['password'],
        ], JSON_THROW_ON_ERROR);

        $aad = [
            'office_id' => $officeId,
            'client_id' => $client->id,
            'fingerprint' => $meta['fingerprint_sha256'],
        ];

        // O filesystem não participa da transação SQL. Grave o novo objeto antes,
        // compense-o em rollback e só invalide os antigos depois do commit.
        $objectId = $this->store->put($payload, $aad);
        $superseded = [];

        try {
            $credential = DB::transaction(function () use (
                $client,
                $meta,
                $objectId,
                $officeId,
                $holder,
                &$superseded,
            ): ClientCredential {
                // Serializa substituições concorrentes mesmo quando ainda não há A1 ativo.
                Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();

                $previous = ClientCredential::query()
                    ->where('client_id', $client->id)
                    ->where('status', CredentialStatus::Active)
                    ->lockForUpdate()
                    ->get();

                foreach ($previous as $old) {
                    $superseded[] = [
                        'id' => $old->id,
                        'object_id' => $old->vault_object_id,
                    ];
                    $old->status = CredentialStatus::Superseded;
                    $old->superseded_at = now();
                    $old->save();
                }

                return ClientCredential::query()->create([
                    'office_id' => $officeId,
                    'client_id' => $client->id,
                    'status' => CredentialStatus::Active,
                    'subject_name' => $meta['subject_name'],
                    'holder_cnpj' => $holder->value(),
                    'fingerprint_sha256' => $meta['fingerprint_sha256'],
                    'valid_from' => $meta['valid_from'],
                    'valid_to' => $meta['valid_to'],
                    'vault_object_id' => $objectId,
                    'activated_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            try {
                $this->store->delete($objectId);
            } catch (Throwable $cleanupError) {
                report(new RuntimeException('Falha ao compensar objeto de credencial não ativada.', 0, $cleanupError));
            }

            throw $e;
        }

        foreach ($superseded as $old) {
            $this->invalidateSupersededObject($old['id'], $old['object_id']);
        }

        return $credential;
    }

    public function activeFor(Client $client): ?ClientCredential
    {
        return ClientCredential::query()
            ->where('client_id', $client->id)
            ->where('status', CredentialStatus::Active)
            ->first();
    }

    /**
     * Material sensível apenas em memória para mTLS — nunca expor via API.
     *
     * @return array{pfx: string, password: string}|null
     */
    public function loadPfxMaterial(ClientCredential $credential): ?array
    {
        if (! $credential->status->isUsable()) {
            return null;
        }

        if ($credential->valid_to->isPast()) {
            $credential->status = CredentialStatus::Expired;
            $credential->save();
            $this->blockCursorsForClient((int) $credential->client_id, 'Credencial A1 expirada.');

            return null;
        }

        $aad = [
            'office_id' => $credential->office_id,
            'client_id' => $credential->client_id,
            'fingerprint' => $credential->fingerprint_sha256,
        ];

        $json = $this->store->get($credential->vault_object_id, $aad);
        /** @var array{pfx: string, password: string} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $pfx = base64_decode((string) ($data['pfx'] ?? ''), true);
        if ($pfx === false || $pfx === '') {
            throw new RuntimeException('Material PFX corrompido no cofre.');
        }

        return [
            'pfx' => $pfx,
            'password' => (string) ($data['password'] ?? ''),
        ];
    }

    /**
     * @return array{credentials: int, cursors_blocked: int}
     */
    public function refreshExpiryAlerts(): array
    {
        $credentialsUpdated = 0;
        $cursorsBlocked = 0;
        $now = now();

        ClientCredential::query()
            ->where('status', CredentialStatus::Active)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($now, &$credentialsUpdated, &$cursorsBlocked): void {
                foreach ($rows as $credential) {
                    /** @var ClientCredential $credential */
                    if ($credential->valid_to->isPast()) {
                        $credential->status = CredentialStatus::Expired;
                        $credential->save();
                        $credentialsUpdated++;
                        $cursorsBlocked += $this->blockCursorsForClient(
                            (int) $credential->client_id,
                            'Credencial A1 expirada.',
                        );

                        continue;
                    }

                    // diffInDays com false: negativo se valid_to está no passado (já tratado).
                    $days = (int) floor($now->floatDiffInRealDays($credential->valid_to, false));
                    $changed = false;
                    if ($days <= 30 && ! $credential->expires_alert_30) {
                        $credential->expires_alert_30 = true;
                        $changed = true;
                    }
                    if ($days <= 7 && ! $credential->expires_alert_7) {
                        $credential->expires_alert_7 = true;
                        $changed = true;
                    }
                    if ($days <= 1 && ! $credential->expires_alert_1) {
                        $credential->expires_alert_1 = true;
                        $changed = true;
                    }
                    if ($changed) {
                        $credential->save();
                        $credentialsUpdated++;
                    }
                }
            });

        return [
            'credentials' => $credentialsUpdated,
            'cursors_blocked' => $cursorsBlocked,
        ];
    }

    private function blockCursorsForClient(int $clientId, string $reason): int
    {
        $blocked = 0;

        SyncCursor::query()
            ->whereHas('establishment', fn ($q) => $q->where('client_id', $clientId))
            ->where('status', '!=', SyncCursorStatus::Blocked)
            ->orderBy('id')
            ->chunkById(100, function ($cursors) use ($reason, &$blocked): void {
                foreach ($cursors as $cursor) {
                    /** @var SyncCursor $cursor */
                    $cursor->status = SyncCursorStatus::Blocked;
                    $cursor->last_error = $reason;
                    $cursor->locked_at = null;
                    $cursor->lock_owner = null;
                    $cursor->save();
                    $blocked++;
                }
            });

        return $blocked;
    }

    private function invalidateSupersededObject(int $credentialId, string $objectId): void
    {
        try {
            $this->store->delete($objectId);

            ClientCredential::query()
                ->whereKey($credentialId)
                ->where('status', CredentialStatus::Superseded)
                ->where('vault_object_id', $objectId)
                ->update(['vault_object_id' => '00000000000000000000000000']);
        } catch (Throwable $e) {
            // A nova credencial já está ativa. Preserve a referência para permitir
            // uma nova tentativa operacional, sem derrubar o material recém-ativado.
            report(new RuntimeException('Falha ao invalidar objeto de credencial substituída.', 0, $e));
        }
    }
}
