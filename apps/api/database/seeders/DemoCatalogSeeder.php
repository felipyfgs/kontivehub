<?php

namespace Database\Seeders;

use App\Enums\AdnDocumentType;
use App\Enums\CredentialStatus;
use App\Enums\FiscalRole;
use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Enums\SyncCursorStatus;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\DfeDocument;
use App\Models\DocumentInterest;
use App\Models\Establishment;
use App\Models\Export;
use App\Models\NfseEvent;
use App\Models\NfseNote;
use App\Models\Office;
use App\Models\SyncCursor;
use App\Models\SyncRun;
use App\Models\User;
use Database\Factories\EstablishmentFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Dados ricos só para local/testing — preenche clientes, estabelecimentos,
 * certificados (metadados), sync, notas, eventos e exportações para o painel.
 *
 * Não grava PFX real; vault_object_id é fictício.
 */
class DemoCatalogSeeder extends Seeder
{
    private const MARKER = '[seed-dev]';

    private const ENVIRONMENT = 'restricted_production';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('DemoCatalogSeeder só pode rodar em local/testing.');
        }

        $office = Office::query()->where('slug', 'demo')->first()
            ?? Office::query()->firstOrCreate(
                ['slug' => 'demo'],
                ['name' => 'Escritório Demo', 'is_active' => true],
            );

        $operator = User::query()->where('email', 'operador@example.com')->first();

        DB::transaction(function () use ($office, $operator): void {
            $this->purgePreviousSeed($office->id);

            $alpha = $this->seedClient($office, [
                'legal_name' => 'Alpha Serviços Contábeis LTDA',
                'root_cnpj' => '11222333',
                'notes' => self::MARKER.' Cliente emitente principal com 2 estabelecimentos.',
            ], [
                ['branch' => '0001', 'trade_name' => 'Alpha Matriz SP', 'is_matrix' => true],
                ['branch' => '0002', 'trade_name' => 'Alpha Filial RJ', 'is_matrix' => false],
            ], CredentialStatus::Active, now()->addDays(20)); // expira em 20d → KPI credenciais

            $beta = $this->seedClient($office, [
                'legal_name' => 'Beta Tecnologia ME',
                'root_cnpj' => '44555666',
                'notes' => self::MARKER.' Cliente tomador; sync bloqueado para demo de alertas.',
            ], [
                ['branch' => '0001', 'trade_name' => 'Beta Matriz', 'is_matrix' => true],
            ], CredentialStatus::Active, now()->addYear());

            $gamma = $this->seedClient($office, [
                'legal_name' => 'Gamma Intermediação SA',
                'root_cnpj' => '77888999',
                'notes' => self::MARKER.' Cliente com certificado a vencer em 5 dias.',
                'is_active' => true,
            ], [
                ['branch' => '0001', 'trade_name' => 'Gamma HQ', 'is_matrix' => true],
            ], CredentialStatus::Active, now()->addDays(5));

            $this->seedSyncAndNotes($office, $alpha, $beta, $gamma);
            $this->seedExports($office, $operator);
        });
    }

    private function purgePreviousSeed(int $officeId): void
    {
        $clientIds = Client::query()
            ->where('office_id', $officeId)
            ->where('notes', 'like', '%'.self::MARKER.'%')
            ->pluck('id');

        if ($clientIds->isEmpty()) {
            // também limpa notas seed por access_key prefix se reexecutar parcialmente
            NfseNote::query()
                ->where('office_id', $officeId)
                ->where('access_key', 'like', 'SEEDDEV%')
                ->each(function (NfseNote $note): void {
                    $docId = $note->dfe_document_id;
                    $note->delete();
                    NfseEvent::query()->where('dfe_document_id', $docId)->delete();
                    DocumentInterest::query()->where('dfe_document_id', $docId)->delete();
                    DfeDocument::query()->whereKey($docId)->delete();
                });

            return;
        }

        $establishmentIds = Establishment::query()->whereIn('client_id', $clientIds)->pluck('id');

        $cursorIds = SyncCursor::query()->whereIn('establishment_id', $establishmentIds)->pluck('id');
        SyncRun::query()->whereIn('sync_cursor_id', $cursorIds)->delete();
        SyncCursor::query()->whereIn('id', $cursorIds)->delete();

        $docIds = DocumentInterest::query()->whereIn('establishment_id', $establishmentIds)->pluck('dfe_document_id')
            ->merge(
                NfseNote::query()->where('office_id', $officeId)->where('access_key', 'like', 'SEEDDEV%')->pluck('dfe_document_id')
            )
            ->unique()
            ->values();

        NfseEvent::query()->whereIn('dfe_document_id', $docIds)->delete();
        NfseNote::query()->whereIn('dfe_document_id', $docIds)->delete();
        DocumentInterest::query()->whereIn('dfe_document_id', $docIds)->delete();
        DfeDocument::query()->whereIn('id', $docIds)->delete();

        ClientCredential::query()->whereIn('client_id', $clientIds)->delete();
        Establishment::query()->whereIn('id', $establishmentIds)->delete();
        Client::query()->whereIn('id', $clientIds)->delete();

        Export::query()
            ->where('office_id', $officeId)
            ->where('filters->seed', self::MARKER)
            ->delete();
    }

    /**
     * @param  array{legal_name: string, root_cnpj: string, notes: string, is_active?: bool}  $clientData
     * @param  list<array{branch: string, trade_name: string, is_matrix: bool}>  $branches
     * @return array{client: Client, establishments: list<Establishment>}
     */
    private function seedClient(
        Office $office,
        array $clientData,
        array $branches,
        CredentialStatus $credentialStatus,
        \DateTimeInterface $validTo,
    ): array {
        $client = Client::query()->create([
            'office_id' => $office->id,
            'legal_name' => $clientData['legal_name'],
            'root_cnpj' => strtoupper(substr($clientData['root_cnpj'], 0, 8)),
            'notes' => $clientData['notes'],
            'is_active' => $clientData['is_active'] ?? true,
            'registration_source' => RegistrationSource::Legacy,
        ]);

        $establishments = [];
        foreach ($branches as $branch) {
            $cnpj = EstablishmentFactory::cnpjWithRoot($client->root_cnpj, $branch['branch']);
            $establishments[] = Establishment::query()->create([
                'office_id' => $office->id,
                'client_id' => $client->id,
                'cnpj' => $cnpj,
                'trade_name' => $branch['trade_name'],
                'is_matrix' => $branch['is_matrix'],
                'is_active' => true,
                'capture_enabled' => true,
                'registration_status' => RegistrationStatus::Unknown,
                'registration_source' => RegistrationSource::Legacy,
            ]);
        }

        $matrixCnpj = $establishments[0]->cnpj;
        ClientCredential::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'status' => $credentialStatus,
            'subject_name' => $client->legal_name,
            'holder_cnpj' => $matrixCnpj,
            'fingerprint_sha256' => hash('sha256', 'seed-dev-cert-'.$client->root_cnpj),
            'valid_from' => now()->subYear(),
            'valid_to' => $validTo,
            'vault_object_id' => (string) Str::ulid(),
            'activated_at' => now()->subMonths(6),
            'expires_alert_30' => $validTo <= now()->addDays(30),
            'expires_alert_7' => $validTo <= now()->addDays(7),
            'expires_alert_1' => $validTo <= now()->addDay(),
        ]);

        return ['client' => $client, 'establishments' => $establishments];
    }

    /**
     * @param  array{client: Client, establishments: list<Establishment>}  $alpha
     * @param  array{client: Client, establishments: list<Establishment>}  $beta
     * @param  array{client: Client, establishments: list<Establishment>}  $gamma
     */
    private function seedSyncAndNotes(Office $office, array $alpha, array $beta, array $gamma): void
    {
        $env = self::ENVIRONMENT;

        // Alpha matriz: IDLE saudável
        $cursorAlpha1 = $this->createCursor($office, $alpha['establishments'][0], $env, [
            'last_nsu' => 120,
            'status' => SyncCursorStatus::Idle,
            'next_sync_at' => now()->addHour(),
            'last_success_at' => now()->subMinutes(40),
        ]);
        $this->createRun($office, $cursorAlpha1, 'COMPLETED', 'SCHEDULED', 3, 12, 100, 120, now()->subHour(), now()->subMinutes(50));
        $this->createRun($office, $cursorAlpha1, 'COMPLETED', 'MANUAL', 1, 5, 115, 120, now()->subMinutes(45), now()->subMinutes(40));

        // Alpha filial: WAITING / due
        $cursorAlpha2 = $this->createCursor($office, $alpha['establishments'][1], $env, [
            'last_nsu' => 45,
            'status' => SyncCursorStatus::Waiting,
            'next_sync_at' => now()->subMinutes(10),
            'last_success_at' => now()->subHours(3),
        ]);
        $this->createRun($office, $cursorAlpha2, 'COMPLETED', 'SCHEDULED', 2, 8, 30, 45, now()->subHours(3), now()->subHours(3)->addMinutes(5));

        // Beta: BLOCKED com falhas de decode
        $cursorBeta = $this->createCursor($office, $beta['establishments'][0], $env, [
            'last_nsu' => 10,
            'status' => SyncCursorStatus::Blocked,
            'consecutive_decode_failures' => 5,
            'attempts' => 5,
            'next_sync_at' => null,
            'last_success_at' => now()->subDays(2),
            'last_error' => 'seed-dev: 5 falhas consecutivas de decode Base64/GZip (simulado).',
        ]);
        $this->createRun($office, $cursorBeta, 'FAILED', 'SCHEDULED', 1, 0, 10, 10, now()->subHours(2), now()->subHours(2)->addMinute(), 'decode failure simulated');
        $this->createRun($office, $cursorBeta, 'FAILED', 'MANUAL', 1, 0, 10, 10, now()->subHour(), now()->subHour()->addMinute(), 'decode failure simulated');

        // Gamma: ERROR transitório
        $cursorGamma = $this->createCursor($office, $gamma['establishments'][0], $env, [
            'last_nsu' => 3,
            'status' => SyncCursorStatus::Error,
            'attempts' => 2,
            'next_sync_at' => now()->subMinutes(5),
            'last_success_at' => now()->subDay(),
            'last_error' => 'seed-dev: timeout ADN simulado.',
        ]);
        $this->createRun($office, $cursorGamma, 'FAILED', 'SCHEDULED', 0, 0, 3, 3, now()->subHours(5), now()->subHours(5)->addSeconds(30), 'timeout');

        $nsu = 1;
        $notesSpec = [
            // emitente Alpha matriz
            [
                'est' => $alpha['establishments'][0],
                'role' => FiscalRole::Issuer,
                'issuer' => $alpha['establishments'][0]->cnpj,
                'taker' => $beta['establishments'][0]->cnpj,
                'status' => 'AUTHORIZED',
                'amount' => 1500.50,
                'days_ago' => 2,
            ],
            [
                'est' => $alpha['establishments'][0],
                'role' => FiscalRole::Issuer,
                'issuer' => $alpha['establishments'][0]->cnpj,
                'taker' => $gamma['establishments'][0]->cnpj,
                'status' => 'AUTHORIZED',
                'amount' => 3200.00,
                'days_ago' => 5,
            ],
            [
                'est' => $alpha['establishments'][0],
                'role' => FiscalRole::Issuer,
                'issuer' => $alpha['establishments'][0]->cnpj,
                'taker' => $beta['establishments'][0]->cnpj,
                'status' => 'CANCELLED',
                'amount' => 800.00,
                'days_ago' => 12,
                'event' => 'CANCELAMENTO',
            ],
            // Alpha filial
            [
                'est' => $alpha['establishments'][1],
                'role' => FiscalRole::Issuer,
                'issuer' => $alpha['establishments'][1]->cnpj,
                'taker' => $beta['establishments'][0]->cnpj,
                'status' => 'AUTHORIZED',
                'amount' => 990.25,
                'days_ago' => 1,
            ],
            // Beta como tomador
            [
                'est' => $beta['establishments'][0],
                'role' => FiscalRole::Taker,
                'issuer' => $alpha['establishments'][0]->cnpj,
                'taker' => $beta['establishments'][0]->cnpj,
                'status' => 'AUTHORIZED',
                'amount' => 450.00,
                'days_ago' => 3,
            ],
            [
                'est' => $beta['establishments'][0],
                'role' => FiscalRole::Taker,
                'issuer' => $gamma['establishments'][0]->cnpj,
                'taker' => $beta['establishments'][0]->cnpj,
                'status' => 'UNKNOWN',
                'amount' => 2100.00,
                'days_ago' => 7,
            ],
            // Gamma intermediário
            [
                'est' => $gamma['establishments'][0],
                'role' => FiscalRole::Intermediary,
                'issuer' => $alpha['establishments'][0]->cnpj,
                'taker' => $beta['establishments'][0]->cnpj,
                'intermediary' => $gamma['establishments'][0]->cnpj,
                'status' => 'AUTHORIZED',
                'amount' => 175.90,
                'days_ago' => 4,
            ],
            [
                'est' => $gamma['establishments'][0],
                'role' => FiscalRole::Issuer,
                'issuer' => $gamma['establishments'][0]->cnpj,
                'taker' => $alpha['establishments'][0]->cnpj,
                'status' => 'AUTHORIZED',
                'amount' => 5600.00,
                'days_ago' => 8,
            ],
        ];

        foreach ($notesSpec as $i => $spec) {
            $this->createNoteBundle($office, $spec['est'], $env, $nsu++, $i + 1, $spec);
        }
    }

    /**
     * @param  array{
     *   role: FiscalRole,
     *   issuer: string,
     *   taker: string,
     *   intermediary?: string,
     *   status: string,
     *   amount: float,
     *   days_ago: int,
     *   event?: string
     * }  $spec
     */
    private function createNoteBundle(
        Office $office,
        Establishment $establishment,
        string $env,
        int $nsu,
        int $seq,
        array $spec,
    ): void {
        $accessKey = sprintf('SEEDDEV%042d', $seq);
        $issuedAt = now()->subDays($spec['days_ago'])->setTime(10 + ($seq % 8), 15, 0);
        $xml = $this->fakeNfseXml($accessKey, $spec['issuer'], $spec['taker'], (string) $spec['amount']);
        $sha = hash('sha256', $xml);

        $doc = DfeDocument::query()->create([
            'office_id' => $office->id,
            'sha256' => $sha,
            'document_type' => AdnDocumentType::Nfse,
            'schema_version' => '1.01',
            'access_key' => $accessKey,
            'vault_object_id' => (string) Str::ulid(),
            'byte_size' => strlen($xml),
            'parse_status' => 'OK',
            'parse_alert' => null,
        ]);

        DocumentInterest::query()->create([
            'office_id' => $office->id,
            'dfe_document_id' => $doc->id,
            'establishment_id' => $establishment->id,
            'nsu' => $nsu,
            'environment' => $env,
            'fiscal_role' => $spec['role'],
        ]);

        NfseNote::query()->create([
            'office_id' => $office->id,
            'dfe_document_id' => $doc->id,
            'access_key' => $accessKey,
            'issuer_cnpj' => $spec['issuer'],
            'taker_cnpj' => $spec['taker'],
            'intermediary_cnpj' => $spec['intermediary'] ?? null,
            'fiscal_role' => $spec['role'],
            'competence' => $issuedAt->format('Y-m'),
            'issued_at' => $issuedAt,
            'service_amount' => $spec['amount'],
            'status' => $spec['status'],
        ]);

        NfseEvent::query()->create([
            'office_id' => $office->id,
            'dfe_document_id' => $doc->id,
            'access_key' => $accessKey,
            'event_type' => 'AUTHORIZED',
            'event_at' => $issuedAt,
            'status' => 'ACTIVE',
        ]);

        if (! empty($spec['event'])) {
            NfseEvent::query()->create([
                'office_id' => $office->id,
                'dfe_document_id' => $doc->id,
                'access_key' => $accessKey,
                'event_type' => $spec['event'],
                'event_at' => $issuedAt->addDays(1),
                'status' => 'ACTIVE',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function createCursor(Office $office, Establishment $est, string $env, array $attrs): SyncCursor
    {
        return SyncCursor::query()->create(array_merge([
            'office_id' => $office->id,
            'establishment_id' => $est->id,
            'environment' => $env,
            'last_nsu' => 0,
            'status' => SyncCursorStatus::Idle,
            'consecutive_decode_failures' => 0,
            'attempts' => 0,
        ], $attrs));
    }

    private function createRun(
        Office $office,
        SyncCursor $cursor,
        string $status,
        string $trigger,
        int $pages,
        int $docs,
        int $fromNsu,
        int $toNsu,
        \DateTimeInterface $started,
        \DateTimeInterface $finished,
        ?string $error = null,
    ): void {
        SyncRun::query()->create([
            'office_id' => $office->id,
            'sync_cursor_id' => $cursor->id,
            'status' => $status,
            'trigger' => $trigger,
            'triggered_by' => null,
            'pages_processed' => $pages,
            'documents_persisted' => $docs,
            'from_nsu' => $fromNsu,
            'to_nsu' => $toNsu,
            'error_message' => $error,
            'started_at' => $started,
            'finished_at' => $finished,
        ]);
    }

    private function seedExports(Office $office, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        Export::query()->create([
            'office_id' => $office->id,
            'user_id' => $user->id,
            'status' => 'READY',
            'filters' => ['seed' => self::MARKER, 'competence' => now()->format('Y-m')],
            'include_events' => true,
            'storage_path' => null,
            'byte_size' => 128_000,
            'files_count' => 8,
            'expires_at' => now()->addDays(3),
            'completed_at' => now()->subHour(),
        ]);

        Export::query()->create([
            'office_id' => $office->id,
            'user_id' => $user->id,
            'status' => 'PENDING',
            'filters' => ['seed' => self::MARKER, 'status' => 'AUTHORIZED'],
            'include_events' => false,
            'files_count' => 0,
        ]);

        Export::query()->create([
            'office_id' => $office->id,
            'user_id' => $user->id,
            'status' => 'PROCESSING',
            'filters' => ['seed' => self::MARKER, 'client_id' => 1],
            'include_events' => true,
            'files_count' => 0,
        ]);

        Export::query()->create([
            'office_id' => $office->id,
            'user_id' => $user->id,
            'status' => 'FAILED',
            'filters' => ['seed' => self::MARKER],
            'include_events' => false,
            'error_message' => 'seed-dev: falha simulada ao montar ZIP.',
            'completed_at' => now()->subMinutes(30),
        ]);
    }

    private function fakeNfseXml(string $accessKey, string $issuer, string $taker, string $amount): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!-- seed-dev only; not a real NFS-e -->
<NFSe>
  <infNFSe Id="{$accessKey}">
    <chNFSe>{$accessKey}</chNFSe>
    <emit><CNPJ>{$issuer}</CNPJ></emit>
    <toma><CNPJ>{$taker}</CNPJ></toma>
    <valores><vServ>{$amount}</vServ></valores>
  </infNFSe>
</NFSe>
XML;
    }
}
