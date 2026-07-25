<?php

namespace Database\Seeders;

use App\Enums\CredentialStatus;
use App\Enums\DctfwebArtifactKind;
use App\Enums\DctfwebTransmissionStatus;
use App\Enums\EsocialEventCode;
use App\Enums\FgtsIndependentState;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalGuideEmissionStatus;
use App\Enums\FiscalGuidePaymentStatus;
use App\Enums\FiscalLinkStatus;
use App\Enums\FiscalMutability;
use App\Enums\FiscalPaymentStatus;
use App\Enums\FiscalPendingStatus;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Enums\MailboxDteStatus;
use App\Enums\MailboxMessagesConsultStatus;
use App\Enums\MailboxTriageStatus;
use App\Enums\MitEncerramentoStatus;
use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Enums\SerproConsumptionClass;
use App\Enums\SerproUsageResult;
use App\Enums\SyncCursorStatus;
use App\Enums\TaxGuideEmissionStatus;
use App\Enums\TaxGuidePaymentStatus;
use App\Enums\TaxInstallmentModality;
use App\Enums\TaxInstallmentParcelStatus;
use App\Enums\TaxInstallmentPaymentStatus;
use App\Enums\TaxObligationApplicability;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\ClientTaxRegimePeriod;
use App\Models\DctfwebDarfDocument;
use App\Models\DctfwebDeclaration;
use App\Models\DctfwebEvidenceVersion;
use App\Models\EsocialEventEvidence;
use App\Models\Establishment;
use App\Models\FgtsCompetenceStatus;
use App\Models\FiscalCategory;
use App\Models\FiscalCompetence;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalFinding;
use App\Models\FiscalGuideStub;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalMonitoringSchedule;
use App\Models\FiscalPendingItem;
use App\Models\FiscalSnapshot;
use App\Models\MailboxAlert;
use App\Models\MailboxAttachment;
use App\Models\MailboxContributorState;
use App\Models\MailboxMessage;
use App\Models\MitApuracao;
use App\Models\Office;
use App\Models\OfficeFiscalCategoryLink;
use App\Models\SerproApiUsageEntry;
use App\Models\SerproUsageMonthlyAggregate;
use App\Models\SyncCursor;
use App\Models\SyncRun;
use App\Models\TaxDeliveryEvidence;
use App\Models\TaxGuide;
use App\Models\TaxGuideVersion;
use App\Models\TaxInstallmentOrder;
use App\Models\TaxInstallmentParcel;
use App\Models\TaxInstallmentPayment;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Models\TaxObligationVersion;
use App\Services\Fiscal\Demo\DemoContentFactory;
use App\Services\Fiscal\Demo\DemoEnvironmentGuard;
use App\Services\Fiscal\Demo\DemoFixturePurger;
use App\Services\Fiscal\Demo\DemoVaultWriter;
use Carbon\CarbonImmutable;
use Database\Factories\EstablishmentFactory;
use Database\Seeders\Demo\FiscalDemoManifest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Dataset fiscal demonstrativo do office `demo` (local/testing only).
 *
 * Idempotente: purga somente fixtures marcadas e recria a partir do manifesto.
 * Não cria credencial/contrato SERPRO sintético; ledger em shadow_mode.
 */
class FiscalMonitoringDemoSeeder extends Seeder
{
    private DemoEnvironmentGuard $guard;

    private DemoFixturePurger $purger;

    private DemoContentFactory $content;

    private DemoVaultWriter $vault;

    private FiscalDemoManifest $manifest;

    private CarbonImmutable $anchor;

    private string $marker;

    private string $prefix;

    private string $version;

    /** @var array<string, FiscalCategory> */
    private array $categories = [];

    /** @var array<string, array{client: Client, establishment: Establishment, spec: array}> */
    private array $clientMap = [];

    /** @var array<string, int> */
    private array $counts = [];

    public function run(): void
    {
        $this->guard = app(DemoEnvironmentGuard::class);
        $this->purger = app(DemoFixturePurger::class);
        $this->content = app(DemoContentFactory::class);
        $this->vault = app(DemoVaultWriter::class);
        $this->manifest = FiscalDemoManifest::fromConfig();
        $this->anchor = $this->manifest->anchor();
        $this->marker = $this->guard->fixtureMarker();
        $this->prefix = $this->guard->correlationPrefix();
        $this->version = $this->manifest->version();

        $office = $this->guard->assertCanSeed();

        $summary = DB::transaction(function () use ($office): array {
            $purged = $this->purger->purgeDemoOffice($office);

            $this->loadCategories();
            $this->seedClients($office);
            $this->seedCategoryLinksAndSchedules($office);
            $this->seedCoreSituations($office);
            $this->seedSimplesMei($office);
            $this->seedDctfwebMit($office);
            $this->seedParcelamentos($office);
            $this->seedSitfis($office);
            $this->seedMailbox($office);
            $this->seedDeclarations($office);
            $this->seedGuides($office);
            $this->seedFgts($office);
            $this->seedUsageLedger($office);
            $this->seedDecodeBlockedCursor($office);
            $this->seedSentinelOffice();

            return [
                'purged_clients' => $purged['clients'],
                'seeded_clients' => count($this->clientMap),
                'manifest_version' => $this->version,
                'anchor_at' => $this->anchor->toIso8601String(),
                'counts' => $this->counts,
            ];
        });

        if ($this->command !== null) {
            $this->command->info('FiscalMonitoringDemoSeeder OK: '.json_encode($summary, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Contagens sanitizadas (sem IDs de cofre / segredos).
     *
     * @return array<string, int|string>
     */
    public function lastSanitizedCounts(): array
    {
        return array_merge([
            'manifest_version' => $this->version ?? $this->guard->manifestVersion(),
            'clients' => count($this->clientMap),
        ], $this->counts);
    }

    private function loadCategories(): void
    {
        foreach (FiscalCategory::query()->get() as $cat) {
            $this->categories[$cat->code] = $cat;
        }
        if ($this->categories === []) {
            throw new LogicException('fiscal_categories vazias — rode migrations antes do seeder demo.');
        }
    }

    private function seedClients(Office $office): void
    {
        foreach ($this->manifest->clients() as $spec) {
            $client = Client::query()->create([
                'office_id' => $office->id,
                'legal_name' => $spec['legal_name'],
                'display_name' => $spec['trade_name'],
                'root_cnpj' => strtoupper($spec['root_cnpj']),
                'tax_regime' => $spec['regime']->value,
                'notes' => $this->marker.' '.$spec['key'].' '.$spec['focus']
                    .' | logical='.$spec['key'].' | v='.$this->version,
                'is_active' => true,
                'registration_source' => RegistrationSource::Legacy,
            ]);

            $cnpj = EstablishmentFactory::cnpjWithRoot($spec['root_cnpj'], '0001');
            $est = Establishment::query()->create([
                'office_id' => $office->id,
                'client_id' => $client->id,
                'cnpj' => $cnpj,
                'trade_name' => $spec['trade_name'],
                'is_matrix' => true,
                'is_active' => true,
                'capture_enabled' => true,
                'registration_status' => RegistrationStatus::Unknown,
                'registration_source' => RegistrationSource::Legacy,
            ]);

            // Metadados públicos sintéticos de certificado — sem PFX real.
            ClientCredential::query()->create([
                'office_id' => $office->id,
                'client_id' => $client->id,
                'status' => CredentialStatus::Active,
                'subject_name' => $client->legal_name,
                'holder_cnpj' => $cnpj,
                'fingerprint_sha256' => hash('sha256', 'demo-fixture-cert-'.$spec['key']),
                'valid_from' => $this->anchor->subYear(),
                'valid_to' => $this->anchor->addMonths(8),
                'vault_object_id' => (string) Str::ulid(),
                'activated_at' => $this->anchor->subMonths(6),
                'expires_alert_30' => false,
                'expires_alert_7' => false,
                'expires_alert_1' => false,
            ]);

            $this->clientMap[$spec['key']] = [
                'client' => $client,
                'establishment' => $est,
                'spec' => $spec,
            ];
        }
        $this->inc('clients', count($this->clientMap));
    }

    private function seedCategoryLinksAndSchedules(Office $office): void
    {
        $moduleToCat = [
            'simples_mei' => ['SIMPLES_NACIONAL', 'MEI'],
            'dctfweb_mit' => ['DCTFWEB', 'MIT'],
            'parcelamentos' => ['PARCELAMENTOS'],
            'sitfis' => ['SITFIS'],
            'mailbox' => ['CAIXA_POSTAL'],
            'declaracoes' => ['DECLARACOES'],
            'guias' => ['GUIAS'],
            'fgts' => ['FGTS'],
        ];

        foreach ($this->clientMap as $key => $row) {
            /** @var TaxRegimeCode $regime */
            $regime = $row['spec']['regime'];
            $modules = $row['spec']['modules'];
            $linked = [];

            foreach ($modules as $mod) {
                foreach ($moduleToCat[$mod] ?? [] as $code) {
                    if ($code === 'SIMPLES_NACIONAL' && ! $regime->isSimplesFamily() && $regime !== TaxRegimeCode::Unknown) {
                        // ainda cria link NOT_APPLICABLE via coverage no núcleo
                    }
                    if ($code === 'MEI' && ! $regime->isMeiFamily()) {
                        continue;
                    }
                    if ($code === 'SIMPLES_NACIONAL' && $regime->isMeiFamily()) {
                        continue;
                    }
                    $linked[$code] = true;
                }
            }

            // Sempre garante vínculo principal coerente com regime
            if ($regime->isSimplesFamily()) {
                $linked['SIMPLES_NACIONAL'] = true;
            }
            if ($regime->isMeiFamily()) {
                $linked['MEI'] = true;
            }

            $minute = (crc32($key) % 50) + 1;
            foreach (array_keys($linked) as $code) {
                $cat = $this->categories[$code] ?? null;
                if ($cat === null) {
                    continue;
                }
                $link = OfficeFiscalCategoryLink::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $row['client']->id,
                    'fiscal_category_id' => $cat->id,
                    'status' => FiscalLinkStatus::Active,
                    'coverage' => $this->coverageOf($cat),
                    'activated_at' => $this->anchor->subMonths(3),
                    'notes' => $this->marker.' link '.$code,
                    'created_by' => null,
                ]);
                $this->inc('category_links');

                FiscalMonitoringSchedule::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $row['client']->id,
                    'fiscal_category_id' => $cat->id,
                    'category_link_id' => $link->id,
                    'system_code' => $cat->system_code ?? 'INTEGRA_CONTADOR',
                    'service_code' => $cat->service_code ?? $code,
                    'operation_code' => 'MONITOR',
                    'is_enabled' => true,
                    'interval_minutes' => 60,
                    'preferred_minute' => $minute,
                    'next_run_at' => $this->anchor->addHour()->setMinute($minute),
                    'last_run_at' => $this->anchor->subHours(2),
                    'last_success_at' => $this->anchor->subHours(2),
                    'last_result' => FiscalRunResult::Success,
                    'metadata' => $this->meta("sched.{$key}.{$code}"),
                ]);
                $this->inc('schedules');
            }
        }
    }

    private function seedCoreSituations(Office $office): void
    {
        // Um run/snapshot/finding/pending por situação primária do manifesto.
        foreach ($this->clientMap as $key => $row) {
            /** @var FiscalSituation $sit */
            $sit = $row['spec']['primary_situation'];
            $catCode = match ($row['spec']['regime']) {
                TaxRegimeCode::Mei => 'MEI',
                TaxRegimeCode::SimplesNacional => 'SIMPLES_NACIONAL',
                TaxRegimeCode::LucroPresumido, TaxRegimeCode::LucroReal => 'DCTFWEB',
                default => 'SITFIS',
            };
            $cat = $this->categories[$catCode] ?? $this->categories['SITFIS'];

            $period = $this->manifest->periodKey(-1);
            [$year, $month] = array_map('intval', explode('-', $period));

            $competence = FiscalCompetence::query()->create([
                'office_id' => $office->id,
                'client_id' => $row['client']->id,
                'fiscal_category_id' => $cat->id,
                'period_key' => $period,
                'period_year' => $year,
                'period_month' => $month,
                'situation' => $sit,
                'coverage' => $sit === FiscalSituation::Unsupported
                    ? FiscalCoverage::Unsupported
                    : $this->coverageOf($cat),
                'due_at' => $this->anchor->addDays(10),
                'closed_at' => $sit === FiscalSituation::UpToDate ? $this->anchor->subDay() : null,
                'metadata' => $this->meta("comp.{$key}.{$period}"),
            ]);
            $this->inc('competences');

            $status = match ($sit) {
                FiscalSituation::Processing => FiscalRunStatus::Running,
                FiscalSituation::Error, FiscalSituation::Blocked => FiscalRunStatus::Failed,
                FiscalSituation::Pending => FiscalRunStatus::Completed,
                default => FiscalRunStatus::Completed,
            };
            $result = match ($sit) {
                FiscalSituation::Error => FiscalRunResult::Failed,
                FiscalSituation::Blocked => FiscalRunResult::Blocked,
                FiscalSituation::Processing => null,
                FiscalSituation::Pending, FiscalSituation::Attention => FiscalRunResult::Partial,
                default => FiscalRunResult::Success,
            };

            $run = $this->createRun(
                $office,
                $row['client'],
                $cat,
                $competence,
                $sit,
                $status,
                $result,
                "core.{$key}",
            );

            $artifact = null;
            if (! in_array($sit, [FiscalSituation::Unknown, FiscalSituation::Processing], true)) {
                $artifact = $this->storeEvidence($run, "core.{$key}.evidence", [
                    'situation' => $sit->value,
                    'client_key' => $key,
                ]);
            }

            $snapshot = FiscalSnapshot::query()->create([
                'office_id' => $office->id,
                'run_id' => $run->id,
                'client_id' => $row['client']->id,
                'competence_id' => $competence->id,
                'evidence_artifact_id' => $artifact?->id,
                'system_code' => $run->system_code,
                'service_code' => $run->service_code,
                'operation_code' => $run->operation_code,
                'situation' => $sit,
                'coverage' => $competence->coverage,
                'version' => 1,
                'is_current' => true,
                'normalized' => [
                    'demo_fixture' => true,
                    'client_key' => $key,
                    'situation' => $sit->value,
                    'focus' => $row['spec']['focus'],
                ],
                'observed_at' => $this->anchor->subHours(1),
                'created_at' => $this->anchor->subHours(1),
            ]);
            $this->inc('snapshots');

            if (in_array($sit, [FiscalSituation::Attention, FiscalSituation::Error, FiscalSituation::Pending], true)) {
                $finding = FiscalFinding::query()->create([
                    'office_id' => $office->id,
                    'snapshot_id' => $snapshot->id,
                    'run_id' => $run->id,
                    'client_id' => $row['client']->id,
                    'code' => 'DEMO_'.$sit->value,
                    'severity' => $sit === FiscalSituation::Error
                        ? FiscalFindingSeverity::High
                        : FiscalFindingSeverity::Medium,
                    'title' => 'Finding demo: '.$sit->label(),
                    'detail' => $this->marker.' '.$row['spec']['focus'],
                    'situation' => $sit,
                    'is_active' => true,
                    'metadata' => $this->meta("finding.{$key}"),
                ]);
                $this->inc('findings');

                $logical = "demo.pending.{$key}.".$sit->value;
                FiscalPendingItem::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $row['client']->id,
                    'snapshot_id' => $snapshot->id,
                    'run_id' => $run->id,
                    'fiscal_category_id' => $cat->id,
                    'competence_id' => $competence->id,
                    'finding_id' => $finding->id,
                    'code' => 'DEMO_PENDING_'.$sit->value,
                    'title' => 'Pendência demo '.$key,
                    'detail' => $this->marker.' '.$row['spec']['focus'],
                    'severity' => FiscalFindingSeverity::Medium,
                    'status' => FiscalPendingStatus::Open,
                    'situation' => FiscalSituation::Pending,
                    'due_at' => $this->anchor->addDays(5),
                    'logical_key' => $logical,
                    'open_dedupe_key' => $logical,
                    'metadata' => $this->meta($logical),
                ]);
                $this->inc('pendings');
            }
        }
    }

    private function seedSimplesMei(Office $office): void
    {
        foreach ($this->clientMap as $key => $row) {
            /** @var TaxRegimeCode $regime */
            $regime = $row['spec']['regime'];
            if (! $regime->isSimplesFamily() && ! $regime->isMeiFamily()) {
                // Período N/A para SN em não-optantes
                ClientTaxRegimePeriod::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $row['client']->id,
                    'regime_code' => $regime,
                    'effective_from' => $this->anchor->subYear()->toDateString(),
                    'effective_to' => null,
                    'source_system' => 'DEMO',
                    'source_service' => 'REGIME_APURACAO',
                    'observed_at' => $this->anchor,
                    'metadata' => $this->meta("regime.{$key}"),
                ]);
                $this->inc('regimes');

                continue;
            }

            ClientTaxRegimePeriod::query()->create([
                'office_id' => $office->id,
                'client_id' => $row['client']->id,
                'regime_code' => $regime,
                'effective_from' => $this->anchor->subYears(2)->toDateString(),
                'effective_to' => null,
                'source_system' => $regime->isMeiFamily() ? 'INTEGRA_MEI' : 'INTEGRA_SN',
                'source_service' => 'REGIME_APURACAO',
                'observed_at' => $this->anchor->subDay(),
                'metadata' => $this->meta("regime.{$key}"),
            ]);
            $this->inc('regimes');

            $period = $this->manifest->periodKey(-1);
            $family = $regime->isMeiFamily() ? 'MEI' : 'SN';
            $system = $regime->isMeiFamily() ? 'INTEGRA_MEI' : 'INTEGRA_SN';
            $service = $regime->isMeiFamily() ? 'PGMEI' : 'PGDASD';

            $emission = match ($row['spec']['primary_situation']) {
                FiscalSituation::UpToDate => FiscalGuideEmissionStatus::Issued,
                FiscalSituation::Pending => FiscalGuideEmissionStatus::Stub,
                FiscalSituation::Error => FiscalGuideEmissionStatus::Failed,
                FiscalSituation::Blocked => FiscalGuideEmissionStatus::Blocked,
                default => FiscalGuideEmissionStatus::Stub,
            };
            $payment = match ($row['spec']['primary_situation']) {
                FiscalSituation::UpToDate => FiscalGuidePaymentStatus::Paid,
                FiscalSituation::Pending => FiscalGuidePaymentStatus::Unpaid,
                default => FiscalGuidePaymentStatus::Unknown,
            };

            FiscalGuideStub::query()->create([
                'office_id' => $office->id,
                'client_id' => $row['client']->id,
                'run_id' => null,
                'system_code' => $system,
                'service_code' => $service,
                'operation_code' => 'GERAR_DAS',
                'regime_family' => $family,
                'period_key' => $period,
                'document_number' => 'DEMO-DAS-'.$key.'-'.$period,
                'due_date' => $this->anchor->addDays(20)->toDateString(),
                'amount' => $regime->isMeiFamily() ? 75.00 : 1250.50,
                'emission_status' => $emission,
                'payment_status' => $payment,
                'is_external_call' => false,
                'metadata' => $this->meta("das.{$key}.{$period}", [
                    'obligation' => $regime->isMeiFamily() ? 'PGMEI' : 'PGDAS_D',
                    'defis_or_dasn' => $regime->isMeiFamily() ? 'DASN_SIMEI' : 'DEFIS',
                ]),
            ]);
            $this->inc('guide_stubs');
        }
    }

    private function seedDctfwebMit(Office $office): void
    {
        $targets = ['C05', 'C06', 'C08', 'C11', 'C14', 'C16'];
        foreach ($targets as $key) {
            if (! isset($this->clientMap[$key])) {
                continue;
            }
            $row = $this->clientMap[$key];
            $period = $this->manifest->periodKey(-1);
            $cat = $this->categories['DCTFWEB'];
            $competence = FiscalCompetence::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $row['client']->id)
                ->where('period_key', $period)
                ->first();

            $tx = match ($key) {
                'C05' => DctfwebTransmissionStatus::Error,
                'C11' => DctfwebTransmissionStatus::Pending,
                'C14' => DctfwebTransmissionStatus::Unknown,
                'C06' => DctfwebTransmissionStatus::Transmitted,
                default => DctfwebTransmissionStatus::Transmitted,
            };
            $sit = match ($key) {
                'C05' => FiscalSituation::Error,
                'C11' => FiscalSituation::Pending,
                'C14' => FiscalSituation::Processing,
                'C06' => FiscalSituation::UpToDate,
                default => FiscalSituation::UpToDate,
            };
            $pay = match ($key) {
                'C06' => FiscalPaymentStatus::Paid,
                'C11' => FiscalPaymentStatus::Unpaid,
                default => FiscalPaymentStatus::Unknown,
            };

            $run = $this->createRun(
                $office,
                $row['client'],
                $cat,
                $competence,
                $sit,
                $sit === FiscalSituation::Processing ? FiscalRunStatus::Running : FiscalRunStatus::Completed,
                $sit === FiscalSituation::Error ? FiscalRunResult::Failed : FiscalRunResult::Success,
                "dctf.{$key}",
            );
            $artifact = $this->storeEvidence($run, "dctf.{$key}.recibo", [
                'receipt' => 'DEMO-RX-'.$key,
                'period' => $period,
            ]);

            $decl = DctfwebDeclaration::query()->create([
                'office_id' => $office->id,
                'client_id' => $row['client']->id,
                'competence_id' => $competence?->id,
                'period_key' => $period,
                'declaration_type' => 'ORIGINAL',
                'transmission_status' => $tx,
                'situation' => $sit,
                'coverage' => FiscalCoverage::Full,
                'receipt_number' => $tx === DctfwebTransmissionStatus::Transmitted ? 'DEMO-RX-'.$key : null,
                'transmitted_at' => $tx === DctfwebTransmissionStatus::Transmitted ? $this->anchor->subDays(3) : null,
                'official_at' => $tx === DctfwebTransmissionStatus::Transmitted ? $this->anchor->subDays(3) : null,
                'evidence_version' => 1,
                'payment_status' => $pay,
                'current_snapshot_id' => null,
                'metadata' => $this->meta("dctf.decl.{$key}"),
            ]);
            $this->inc('dctf_declarations');

            DctfwebEvidenceVersion::query()->create([
                'office_id' => $office->id,
                'client_id' => $row['client']->id,
                'declaration_id' => $decl->id,
                'competence_id' => $competence?->id,
                'run_id' => $run->id,
                'evidence_artifact_id' => $artifact->id,
                'artifact_kind' => DctfwebArtifactKind::Recibo,
                'version' => 1,
                'content_sha256' => $artifact->content_sha256,
                'is_current' => true,
                'declaration_type' => 'ORIGINAL',
                'source_version' => 'demo-1',
                'is_retification' => false,
                'observed_at' => $this->anchor->subDays(2),
                'metadata' => $this->meta("dctf.ev.{$key}"),
                'created_at' => $this->anchor->subDays(2),
            ]);
            $this->inc('dctf_evidences');

            // DARF independente do pagamento da declaração
            DctfwebDarfDocument::query()->create([
                'office_id' => $office->id,
                'client_id' => $row['client']->id,
                'declaration_id' => $decl->id,
                'competence_id' => $competence?->id,
                'evidence_version_id' => null,
                'evidence_artifact_id' => $artifact->id,
                'document_number' => 'DEMO-DARF-'.$key,
                'amount' => 3200.00,
                'due_at' => $this->anchor->addDays(12),
                'issued_at' => $this->anchor->subDays(1),
                'payment_status' => $pay,
                'content_sha256' => hash('sha256', 'demo-darf-'.$key),
                'metadata' => $this->meta("dctf.darf.{$key}"),
            ]);
            $this->inc('dctf_darfs');

            // MIT — eixos independentes
            $enc = match ($key) {
                'C11' => MitEncerramentoStatus::Open,
                'C14' => MitEncerramentoStatus::Processing,
                'C05' => MitEncerramentoStatus::Error,
                default => MitEncerramentoStatus::Encerrado,
            };
            MitApuracao::query()->create([
                'office_id' => $office->id,
                'client_id' => $row['client']->id,
                'competence_id' => $competence?->id,
                'period_key' => $period,
                'encerramento_status' => $enc,
                'situacao_status' => $enc->value,
                'dctfweb_transmission_status' => $tx,
                'situation' => $sit,
                'coverage' => FiscalCoverage::Partial,
                'encerrado_at' => $enc === MitEncerramentoStatus::Encerrado ? $this->anchor->subDays(4) : null,
                'observed_at' => $this->anchor->subDay(),
                'metadata' => $this->meta("mit.{$key}"),
            ]);
            $this->inc('mit_apuracoes');
        }
    }

    private function seedParcelamentos(Office $office): void
    {
        $specs = [
            'C01' => ['modality' => TaxInstallmentModality::Parcsn, 'situation' => 'ACTIVE', 'late' => false],
            'C04' => ['modality' => TaxInstallmentModality::ParcsnEsp, 'situation' => 'ACTIVE', 'late' => true],
            'C13' => ['modality' => TaxInstallmentModality::Relpsn, 'situation' => 'DEFAULT_RISK', 'late' => true],
            'C15' => ['modality' => TaxInstallmentModality::Parcsn, 'situation' => 'ACTIVE', 'late' => false],
            'C18' => ['modality' => TaxInstallmentModality::Parcmei, 'situation' => 'BLOCKED', 'late' => false],
            'C12' => ['modality' => TaxInstallmentModality::Pertsn, 'situation' => 'ACTIVE', 'late' => true],
        ];

        foreach ($specs as $key => $cfg) {
            if (! isset($this->clientMap[$key])) {
                continue;
            }
            $row = $this->clientMap[$key];
            /** @var TaxInstallmentModality $mod */
            $mod = $cfg['modality'];
            $cat = $this->categories['PARCELAMENTOS'];
            $run = $this->createRun(
                $office,
                $row['client'],
                $cat,
                null,
                $cfg['late'] ? FiscalSituation::Attention : FiscalSituation::UpToDate,
                FiscalRunStatus::Completed,
                FiscalRunResult::Success,
                "parc.{$key}",
            );

            $order = TaxInstallmentOrder::query()->create([
                'office_id' => $office->id,
                'client_id' => $row['client']->id,
                'run_id' => $run->id,
                'snapshot_id' => null,
                'modality' => $mod,
                'regime' => $mod->regime(),
                'external_order_id' => 'DEMO-ORD-'.$key,
                'situation' => $cfg['situation'],
                'source_status' => $cfg['situation'],
                'requested_at' => $this->anchor->subMonths(6),
                'consolidated_at' => $this->anchor->subMonths(5),
                'parcel_count' => 12,
                'total_amount_cents' => 120_000_00,
                'source_system' => 'INTEGRA_PARCELAMENTO',
                'source_service' => $mod->value,
                'source_operation' => 'CONSULTAR_PARCELAMENTO',
                'evidence_sha256' => hash('sha256', 'demo-parc-'.$key),
                'observed_at' => $this->anchor->subHours(3),
                'metadata' => $this->meta("parc.order.{$key}"),
            ]);
            $this->inc('installment_orders');

            for ($n = 1; $n <= 4; $n++) {
                $due = $this->anchor->startOfMonth()->addMonths($n - 2)->setDay(15);
                $isPast = $due->lt($this->anchor);
                $isPaid = $isPast && ! $cfg['late'] ? true : ($n === 1 && ! $cfg['late']);
                $isLate = $isPast && $cfg['late'] && $n <= 2;

                $status = $isPaid
                    ? TaxInstallmentParcelStatus::Paid
                    : ($isLate ? TaxInstallmentParcelStatus::Attention : TaxInstallmentParcelStatus::Open);
                $payStatus = $isPaid
                    ? TaxInstallmentPaymentStatus::Confirmed
                    : TaxInstallmentPaymentStatus::None;

                $parcel = TaxInstallmentParcel::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $row['client']->id,
                    'order_id' => $order->id,
                    'modality' => $mod,
                    'parcel_key' => sprintf('%02d', $n),
                    'parcel_number' => $n,
                    'status' => $status,
                    'source_status' => $status->value,
                    'due_at' => $due,
                    'amount_cents' => 10_000_00,
                    'document_available' => true,
                    'payment_status' => $payStatus,
                    'paid_at' => $isPaid ? $due->subDays(2) : null,
                    'logical_key' => "demo.parc.{$key}.{$n}",
                    'metadata' => $this->meta("parc.parcel.{$key}.{$n}"),
                ]);
                $this->inc('installment_parcels');

                if ($isPaid) {
                    $payment = TaxInstallmentPayment::query()->create([
                        'office_id' => $office->id,
                        'client_id' => $row['client']->id,
                        'order_id' => $order->id,
                        'parcel_id' => $parcel->id,
                        'modality' => $mod,
                        'status' => 'CONFIRMED',
                        'amount_cents' => 10_000_00,
                        'paid_at' => $due->subDays(2),
                        'payment_ref' => 'DEMO-PAY-'.$key.'-'.$n,
                        'evidence_sha256' => hash('sha256', "demo-pay-{$key}-{$n}"),
                        'run_id' => $run->id,
                        'metadata' => $this->meta("parc.pay.{$key}.{$n}"),
                    ]);
                    $parcel->update(['payment_id' => $payment->id]);
                    $this->inc('installment_payments');
                }
            }
        }
    }

    private function seedSitfis(Office $office): void
    {
        $targets = ['C01', 'C03', 'C07', 'C12', 'C14', 'C17'];
        foreach ($targets as $key) {
            if (! isset($this->clientMap[$key])) {
                continue;
            }
            $row = $this->clientMap[$key];
            $cat = $this->categories['SITFIS'];
            $sit = match ($key) {
                'C03', 'C14' => FiscalSituation::Processing,
                'C07' => FiscalSituation::Unknown,
                'C12', 'C17' => FiscalSituation::Attention,
                default => FiscalSituation::UpToDate,
            };

            $run = $this->createRun(
                $office,
                $row['client'],
                $cat,
                null,
                $sit,
                $sit === FiscalSituation::Processing ? FiscalRunStatus::Running : FiscalRunStatus::Completed,
                $sit === FiscalSituation::Processing ? null : FiscalRunResult::Success,
                "sitfis.{$key}",
                progress: $sit === FiscalSituation::Processing
                    ? ['protocol' => 'DEMO-PROT-'.$key, 'phase' => 'EMITIR_RELATORIO', 'polls' => 2]
                    : ['protocol' => 'DEMO-PROT-'.$key, 'phase' => 'DONE'],
            );

            $ttl = (int) config('fiscal_monitoring.sitfis.snapshot_ttl_seconds', 86400);
            $observed = $key === 'C17'
                ? $this->anchor->subSeconds($ttl + 3600) // expirado
                : $this->anchor->subHours(2);

            $artifact = null;
            if ($sit !== FiscalSituation::Processing) {
                $artifact = $this->storeEvidence($run, "sitfis.{$key}.report", [
                    'protocol' => 'DEMO-PROT-'.$key,
                    'pendencias' => $sit === FiscalSituation::Attention
                        ? [['code' => 'P1', 'title' => 'Pendência sintética SITFIS']]
                        : [],
                ]);
            }

            $snapshot = FiscalSnapshot::query()->create([
                'office_id' => $office->id,
                'run_id' => $run->id,
                'client_id' => $row['client']->id,
                'competence_id' => null,
                'evidence_artifact_id' => $artifact?->id,
                'system_code' => 'INTEGRA_SITFIS',
                'service_code' => 'SITFIS',
                'operation_code' => 'MONITOR',
                'situation' => $sit,
                'coverage' => FiscalCoverage::Full,
                'version' => 1,
                'is_current' => true,
                'normalized' => [
                    'demo_fixture' => true,
                    'protocol' => 'DEMO-PROT-'.$key,
                    'ttl_seconds' => $ttl,
                    'expires_at' => $observed->addSeconds($ttl)->toIso8601String(),
                    'expired' => $observed->addSeconds($ttl)->lt($this->anchor),
                    'findings' => $sit === FiscalSituation::Attention
                        ? [['code' => 'SITFIS_P1', 'title' => 'Pendência normalizada demo']]
                        : [],
                ],
                'observed_at' => $observed,
                'created_at' => $observed,
            ]);
            $this->inc('sitfis_snapshots');

            if ($sit === FiscalSituation::Attention) {
                FiscalFinding::query()->create([
                    'office_id' => $office->id,
                    'snapshot_id' => $snapshot->id,
                    'run_id' => $run->id,
                    'client_id' => $row['client']->id,
                    'code' => 'SITFIS_P1',
                    'severity' => FiscalFindingSeverity::Medium,
                    'title' => 'Pendência SITFIS normalizada',
                    'detail' => $this->marker.' Achado sintético sem JSON bruto na UI.',
                    'situation' => FiscalSituation::Attention,
                    'is_active' => true,
                    'metadata' => $this->meta("sitfis.finding.{$key}"),
                ]);
                $this->inc('findings');
            }
        }
    }

    private function seedMailbox(Office $office): void
    {
        $targets = [
            'C01' => ['triage' => MailboxTriageStatus::Resolved, 'dte' => MailboxDteStatus::Active, 'unread' => false],
            'C04' => ['triage' => MailboxTriageStatus::New, 'dte' => MailboxDteStatus::Active, 'unread' => true, 'critical' => true],
            'C12' => ['triage' => MailboxTriageStatus::InReview, 'dte' => MailboxDteStatus::Active, 'unread' => true, 'critical' => true],
            'C09' => ['triage' => MailboxTriageStatus::New, 'dte' => MailboxDteStatus::Error, 'unread' => false],
            'C16' => ['triage' => MailboxTriageStatus::Resolved, 'dte' => MailboxDteStatus::Inactive, 'unread' => false],
        ];

        foreach ($targets as $key => $cfg) {
            if (! isset($this->clientMap[$key])) {
                continue;
            }
            $row = $this->clientMap[$key];
            $cat = $this->categories['CAIXA_POSTAL'];
            $run = $this->createRun(
                $office,
                $row['client'],
                $cat,
                null,
                ($cfg['critical'] ?? false) ? FiscalSituation::Attention : FiscalSituation::UpToDate,
                FiscalRunStatus::Completed,
                FiscalRunResult::Success,
                "mailbox.{$key}",
            );

            MailboxContributorState::query()->create([
                'office_id' => $office->id,
                'client_id' => $row['client']->id,
                'dte_status' => $cfg['dte'],
                'dte_source' => 'DTE_INDICATOR',
                'dte_observed_at' => $this->anchor->subHours(4),
                'last_dte_run_id' => $run->id,
                'messages_status' => MailboxMessagesConsultStatus::Consulted,
                'messages_source' => 'CAIXA_POSTAL',
                'messages_observed_at' => $this->anchor->subHours(3),
                'last_list_run_id' => $run->id,
                'official_unread_count' => ($cfg['unread'] ?? false) ? 1 : 0,
                'stored_message_count' => 1,
                'metadata' => $this->meta("mailbox.state.{$key}"),
            ]);
            $this->inc('mailbox_states');

            $externalId = $this->prefix.'MSG-'.$key;
            $hash = hash('sha256', $office->id.'|'.$row['client']->id.'|'.$externalId);
            $subject = ($cfg['critical'] ?? false)
                ? 'Intimação sintética — prazo em 5 dias'
                : 'Comunicado geral sintético';
            $body = $this->vault->putMailboxBody($office->id, $subject, "mailbox.body.{$key}");

            $msg = MailboxMessage::query()->create([
                'office_id' => $office->id,
                'client_id' => $row['client']->id,
                'external_id' => $externalId,
                'message_hash' => $hash,
                'source' => 'CAIXA_POSTAL',
                'sensitivity_class' => 'FISCAL_RESTRICTED',
                'category_code' => ($cfg['critical'] ?? false) ? 'INTIMACAO' : 'COMUNICADO',
                'category_label' => ($cfg['critical'] ?? false) ? 'Intimação' : 'Comunicado',
                'sender_code' => 'RFB',
                'sender_label' => 'Receita Federal (simulado)',
                'subject_preview' => $subject,
                'received_at_official' => $this->anchor->subDays(2),
                'due_at' => ($cfg['critical'] ?? false) ? $this->anchor->addDays(5) : null,
                'severity_hint' => ($cfg['critical'] ?? false) ? 'high' : 'low',
                'official_read_indicator' => ! ($cfg['unread'] ?? false),
                'official_read_observed_at' => ($cfg['unread'] ?? false) ? null : $this->anchor->subDay(),
                'triage_status' => $cfg['triage'],
                'triage_at' => $cfg['triage'] !== MailboxTriageStatus::New ? $this->anchor->subHours(6) : null,
                'triage_note' => $cfg['triage'] === MailboxTriageStatus::InReview
                    ? $this->marker.' Em triagem interna'
                    : null,
                'body_vault_object_id' => $body['vault_object_id'],
                'body_sha256' => $body['content_sha256'],
                'body_content_type' => $body['content_type'],
                'body_byte_size' => $body['byte_size'],
                'has_body' => true,
                'attachment_count' => 1,
                'retention_until' => $this->anchor->addYears(7),
                'first_run_id' => $run->id,
                'last_run_id' => $run->id,
                'metadata' => $this->meta("mailbox.msg.{$key}"),
            ]);
            $this->inc('mailbox_messages');

            $att = $this->vault->putMailboxAttachment($office->id, 'anexo-demo.txt', "mailbox.att.{$key}");
            MailboxAttachment::query()->create([
                'office_id' => $office->id,
                'mailbox_message_id' => $msg->id,
                'external_id' => $this->prefix.'ATT-'.$key,
                'filename_sanitized' => 'anexo-demo.txt',
                'content_type' => $att['content_type'],
                'vault_object_id' => $att['vault_object_id'],
                'content_sha256' => $att['content_sha256'],
                'byte_size' => $att['byte_size'],
                'sensitivity_class' => 'FISCAL_RESTRICTED',
                'retention_until' => $this->anchor->addYears(7),
                'metadata' => $this->meta("mailbox.att.{$key}"),
                'created_at' => $this->anchor,
            ]);
            $this->inc('mailbox_attachments');

            if ($cfg['critical'] ?? false) {
                MailboxAlert::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $row['client']->id,
                    'mailbox_message_id' => $msg->id,
                    'severity' => 'high',
                    'title' => 'Prazo de intimação (demo)',
                    'body' => $this->marker.' Alerta sanitizado — sem corpo fiscal.',
                    'deep_link' => '/monitoring/mailbox/'.$msg->id,
                    'is_active' => true,
                    'metadata' => $this->meta("mailbox.alert.{$key}"),
                ]);
                $this->inc('mailbox_alerts');
            }
        }
    }

    private function seedDeclarations(Office $office): void
    {
        $defs = TaxObligationDefinition::query()->get()->keyBy('code');
        $versions = TaxObligationVersion::query()->where('is_current', true)->get()->keyBy('obligation_definition_id');

        foreach ($this->clientMap as $key => $row) {
            /** @var TaxRegimeCode $regime */
            $regime = $row['spec']['regime'];
            $period = $this->manifest->periodKey(-1);
            [$year, $month] = array_map('intval', explode('-', $period));

            foreach (['PGDAS_D', 'DEFIS', 'DASN_SIMEI', 'DCTFWEB'] as $code) {
                $def = $defs->get($code);
                if ($def === null) {
                    continue;
                }
                $ver = $versions->get($def->id);

                $appl = match (true) {
                    $code === 'PGDAS_D' && $regime->isSimplesFamily() => TaxObligationApplicability::Applicable,
                    $code === 'PGDAS_D' && $regime->isMeiFamily() => TaxObligationApplicability::NotApplicable,
                    $code === 'DEFIS' && $regime->isSimplesFamily() => TaxObligationApplicability::Applicable,
                    $code === 'DEFIS' && $regime->isMeiFamily() => TaxObligationApplicability::NotApplicable,
                    $code === 'DASN_SIMEI' && $regime->isMeiFamily() => TaxObligationApplicability::Applicable,
                    $code === 'DASN_SIMEI' && $regime->isSimplesFamily() => TaxObligationApplicability::NotApplicable,
                    $code === 'DCTFWEB' && in_array($regime, [TaxRegimeCode::LucroPresumido, TaxRegimeCode::LucroReal], true) => TaxObligationApplicability::Applicable,
                    $code === 'DCTFWEB' && $regime->isMeiFamily() => TaxObligationApplicability::NotApplicable,
                    $code === 'DCTFWEB' => TaxObligationApplicability::Unknown,
                    default => TaxObligationApplicability::NotApplicable,
                };

                if ($appl === TaxObligationApplicability::NotApplicable && ! in_array($key, ['C06', 'C10'], true)) {
                    // popula N/A só em amostra para não explodir volume
                    if (! in_array($code, ['PGDAS_D', 'DASN_SIMEI'], true)) {
                        continue;
                    }
                }

                $periodKey = in_array($code, ['DEFIS', 'DASN_SIMEI'], true)
                    ? $this->manifest->yearKey(-1)
                    : $period;
                $pYear = (int) substr($periodKey, 0, 4);
                $pMonth = strlen($periodKey) === 7 ? (int) substr($periodKey, 5, 2) : null;

                $delivery = FiscalSituation::Unknown;
                $situation = $appl->toFiscalSituation();
                if ($appl === TaxObligationApplicability::Applicable) {
                    $delivery = match ($row['spec']['primary_situation']) {
                        FiscalSituation::UpToDate => FiscalSituation::UpToDate,
                        FiscalSituation::Pending => FiscalSituation::Pending,
                        FiscalSituation::Error => FiscalSituation::Error,
                        default => FiscalSituation::Pending,
                    };
                    $situation = $delivery;
                }

                $due = $pMonth !== null
                    ? CarbonImmutable::create($pYear, $pMonth, 1)->addMonth()->setDay(20)
                    : CarbonImmutable::create($pYear + 1, 3, 31);

                $proj = TaxObligationProjection::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $row['client']->id,
                    'obligation_definition_id' => $def->id,
                    'obligation_version_id' => $ver?->id,
                    'calendar_version_id' => null,
                    'competence_id' => null,
                    'period_key' => $periodKey,
                    'period_year' => $pYear,
                    'period_month' => $pMonth,
                    'applicability' => $appl,
                    'situation' => $situation,
                    'delivery_status' => $delivery,
                    'due_at' => $due,
                    'due_rule_snapshot' => ['source' => 'demo', 'day' => 20],
                    'applicability_basis' => $this->marker.' regime='.$regime->value,
                    'is_open' => $delivery !== FiscalSituation::UpToDate
                        && $appl === TaxObligationApplicability::Applicable,
                    'closed_at' => $delivery === FiscalSituation::UpToDate ? $this->anchor->subDays(2) : null,
                    'metadata' => $this->meta("decl.{$key}.{$code}"),
                ]);
                $this->inc('declarations');

                if ($delivery === FiscalSituation::UpToDate) {
                    $cat = $this->categories['DECLARACOES'] ?? $this->categories['SIMPLES_NACIONAL'];
                    $run = $this->createRun(
                        $office,
                        $row['client'],
                        $cat,
                        null,
                        FiscalSituation::UpToDate,
                        FiscalRunStatus::Completed,
                        FiscalRunResult::Success,
                        "decl.{$key}.{$code}",
                    );
                    $art = $this->storeEvidence($run, "decl.{$key}.{$code}", [
                        'protocol' => 'DEMO-DECL-'.$key.'-'.$code,
                    ]);
                    $ev = TaxDeliveryEvidence::query()->create([
                        'office_id' => $office->id,
                        'projection_id' => $proj->id,
                        'kind' => 'OFFICIAL_RECEIPT',
                        'protocol_number' => 'DEMO-PROT-'.$key.'-'.$code,
                        'receipt_number' => 'DEMO-RCP-'.$key.'-'.$code,
                        'is_conclusive' => true,
                        'source' => 'DEMO_FIXTURE',
                        'source_version' => '1',
                        'observed_at' => $this->anchor->subDays(2),
                        'evidence_artifact_id' => $art->id,
                        'run_id' => $run->id,
                        'payload_digest' => $art->content_sha256,
                        'metadata' => $this->meta("decl.ev.{$key}.{$code}"),
                    ]);
                    $proj->update([
                        'conclusive_evidence_id' => $ev->id,
                        'evidence_artifact_id' => $art->id,
                    ]);
                    $this->inc('delivery_evidences');
                }
            }
        }
    }

    private function seedGuides(Office $office): void
    {
        $targets = ['C01', 'C02', 'C10', 'C11', 'C13', 'C15', 'C16'];
        foreach ($targets as $i => $key) {
            if (! isset($this->clientMap[$key])) {
                continue;
            }
            $row = $this->clientMap[$key];
            $period = $this->manifest->periodKey(-1);
            $amount = 1500_00 + ($i * 250_00);
            $logical = "demo.guide.{$key}.{$period}";
            $pay = match ($key) {
                'C01', 'C10', 'C15' => TaxGuidePaymentStatus::Confirmed,
                'C02', 'C11' => TaxGuidePaymentStatus::NotConfirmed,
                default => TaxGuidePaymentStatus::Unknown,
            };
            $emission = match ($key) {
                'C13' => TaxGuideEmissionStatus::Rejected,
                'C02' => TaxGuideEmissionStatus::Pending,
                default => TaxGuideEmissionStatus::Confirmed,
            };

            $guide = TaxGuide::query()->create([
                'office_id' => $office->id,
                'client_id' => $row['client']->id,
                'establishment_id' => $row['establishment']->id,
                'system_code' => 'INTEGRA_PAGAMENTO',
                'service_code' => 'GUIA',
                'operation_code' => 'EMITIR_GUIA',
                'competence_period_key' => $period,
                'debit_ref' => 'DEMO-DEB-'.$key,
                'logical_key' => $logical,
                'payment_status' => $pay,
                'payment_confirmed_at' => $pay === TaxGuidePaymentStatus::Confirmed
                    ? $this->anchor->subDays(5)
                    : null,
                'payment_source' => $pay === TaxGuidePaymentStatus::Confirmed ? 'DEMO_FIXTURE' : null,
                'payment_external_id' => $pay === TaxGuidePaymentStatus::Confirmed ? 'DEMO-PAYG-'.$key : null,
                'amount_cents' => $amount,
                'currency' => 'BRL',
                'due_at' => $this->anchor->addDays(15),
                'identifier_code' => 'DEMO-GUIDE-'.$key,
                'metadata' => $this->meta($logical),
            ]);
            $this->inc('guides');

            $doc = $this->vault->putGuideDocument(
                $office->id,
                'DEMO-GUIDE-'.$key,
                $logical,
                $amount,
            );

            $version = TaxGuideVersion::query()->create([
                'office_id' => $office->id,
                'tax_guide_id' => $guide->id,
                'version_number' => 1,
                'is_current' => true,
                'emission_status' => $emission,
                'identifier_code' => 'DEMO-GUIDE-'.$key,
                'amount_cents' => $amount,
                'currency' => 'BRL',
                'due_at' => $this->anchor->addDays(15),
                'valid_until' => $this->anchor->addDays(30),
                'content_sha256' => $doc['content_sha256'],
                'vault_object_id' => $doc['vault_object_id'],
                'content_type' => $doc['content_type'],
                'byte_size' => $doc['byte_size'],
                'idempotency_key' => $this->prefix.'GUIDE-'.$key.'-'.$period,
                'correlation_id' => $this->corr("guide.{$key}"),
                'risk_level' => 'STANDARD',
                'confirmation_summary' => [
                    'disclaimer' => $this->guard->watermark(),
                    'simulated' => true,
                ],
                'sent_at' => $emission !== TaxGuideEmissionStatus::Pending ? $this->anchor->subDays(1) : null,
                'finished_at' => $emission === TaxGuideEmissionStatus::Confirmed
                    ? $this->anchor->subDays(1)
                    : null,
                'metadata' => $this->meta("guide.ver.{$key}"),
            ]);
            $guide->update(['current_version_id' => $version->id]);
            $this->inc('guide_versions');
        }
    }

    private function seedFgts(Office $office): void
    {
        $targets = ['C06', 'C08', 'C11', 'C17'];
        foreach ($targets as $key) {
            if (! isset($this->clientMap[$key])) {
                continue;
            }
            $row = $this->clientMap[$key];
            $period = $this->manifest->periodKey(-1);
            $cat = $this->categories['FGTS'];
            $sit = match ($key) {
                'C08' => FiscalSituation::Unsupported,
                'C17' => FiscalSituation::Attention,
                'C11' => FiscalSituation::Pending,
                default => FiscalSituation::UpToDate,
            };

            $run = $this->createRun(
                $office,
                $row['client'],
                $cat,
                null,
                $sit,
                FiscalRunStatus::Completed,
                FiscalRunResult::Success,
                "fgts.{$key}",
            );

            $artifact = $this->storeEvidence($run, "fgts.{$key}.s1299", [
                'event' => 'S-1299',
                'period' => $period,
            ]);

            foreach ([EsocialEventCode::S1299, EsocialEventCode::S5003] as $event) {
                if ($key === 'C08' && $event === EsocialEventCode::S5003) {
                    // ausência de totalização
                    continue;
                }
                EsocialEventEvidence::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $row['client']->id,
                    'establishment_id' => $row['establishment']->id,
                    'run_id' => $run->id,
                    'fiscal_evidence_artifact_id' => $artifact->id,
                    'competence_period_key' => $period,
                    'event_code' => $event,
                    'event_version' => '1.0',
                    'receipt_number' => 'DEMO-ESOCIAL-'.$key.'-'.$event->value,
                    'establishment_cnpj' => $row['establishment']->cnpj,
                    'content_sha256' => hash('sha256', "demo-esocial-{$key}-{$event->value}"),
                    'vault_object_id' => null,
                    'content_type' => 'application/json',
                    'byte_size' => 128,
                    'source' => 'DEMO_FIXTURE',
                    'source_version' => '1',
                    'occurred_at' => $this->anchor->subDays(8),
                    'observed_at' => $this->anchor->subDays(7),
                    'metadata' => $this->meta("fgts.event.{$key}.{$event->value}"),
                ]);
                $this->inc('esocial_events');
            }

            $closure = $key === 'C08' ? FgtsIndependentState::Unknown : FgtsIndependentState::Confirmed;
            $total = match ($key) {
                'C08' => FgtsIndependentState::Absent,
                'C17' => FgtsIndependentState::Present,
                'C11' => FgtsIndependentState::Absent,
                default => FgtsIndependentState::Present,
            };

            FgtsCompetenceStatus::query()->create([
                'office_id' => $office->id,
                'client_id' => $row['client']->id,
                'establishment_id' => $row['establishment']->id,
                'fiscal_competence_id' => null,
                'run_id' => $run->id,
                'snapshot_id' => null,
                'competence_period_key' => $period,
                'closure_status' => $closure,
                'totalization_status' => $total,
                'guide_status' => FgtsIndependentState::Unsupported,
                'payment_status' => FgtsIndependentState::Unsupported,
                'coverage' => FiscalCoverage::Partial,
                'situation' => $sit,
                'closure_observed_at' => $closure === FgtsIndependentState::Confirmed
                    ? $this->anchor->subDays(8)
                    : null,
                'totalizer_observed_at' => $total === FgtsIndependentState::Present
                    ? $this->anchor->subDays(7)
                    : null,
                'totalizer_due_by' => $this->anchor->subDays(1),
                'last_synced_at' => $this->anchor->subHours(6),
                'limitations' => [
                    'guide' => 'UNSUPPORTED — sem API pública FGTS Digital',
                    'payment' => 'UNSUPPORTED — sem API pública FGTS Digital',
                    'banner' => $this->guard->watermark(),
                ],
                'metadata' => $this->meta("fgts.status.{$key}", [
                    'divergence' => $key === 'C17',
                ]),
            ]);
            $this->inc('fgts_statuses');
        }
    }

    private function seedUsageLedger(Office $office): void
    {
        // Ledger shadow atribuído ao office demo — SEM SerproContract sintético.
        $period = $this->anchor;
        $ops = [
            ['INTEGRA_SN', 'PGDASD', 'MONITOR', SerproConsumptionClass::Consulta],
            ['INTEGRA_DCTFWEB', 'DCTFWEB', 'CONSULTAR_DECLARACAO', SerproConsumptionClass::Consulta],
            ['INTEGRA_SITFIS', 'SITFIS', 'MONITOR', SerproConsumptionClass::Consulta],
            ['INTEGRA_CAIXAPOSTAL', 'CAIXA_POSTAL', 'DETALHE', SerproConsumptionClass::Consulta],
            ['INTEGRA_PARCELAMENTO', 'PARCSN', 'CONSULTAR_PEDIDOS', SerproConsumptionClass::Consulta],
        ];

        $clientId = $this->clientMap['C01']['client']->id ?? null;
        $i = 0;
        foreach ($ops as [$system, $service, $operation, $class]) {
            $i++;
            SerproApiUsageEntry::query()->create([
                'office_id' => $office->id,
                'reservation_id' => null,
                'idempotency_key' => $this->prefix.'USAGE-'.$this->version.'-'.$i,
                'client_id' => $clientId,
                'contributor_ref' => 'C01',
                'system_code' => $system,
                'service_code' => $service,
                'operation_code' => $operation,
                'consumption_class' => $class,
                'quantity' => 1,
                'result' => SerproUsageResult::Success,
                'correlation_id' => $this->corr("usage.{$i}"),
                'price_version_id' => null,
                'estimated_cost_micros' => 15_000,
                'is_billable_attempt' => true,
                'latency_ms' => 120 + $i,
                'http_status' => 200,
                'shadow_mode' => true,
                'occurred_at' => $period->subHours($i),
                'created_at' => $period->subHours($i),
            ]);
            $this->inc('usage_entries');
        }

        SerproUsageMonthlyAggregate::query()->create([
            'scope' => SerproUsageMonthlyAggregate::SCOPE_TENANT,
            'office_id' => $office->id,
            'period_year' => $period->year,
            'period_month' => $period->month,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'consumption_class' => SerproConsumptionClass::Consulta,
            'aggregate_key' => $this->prefix.'AGG-'.$period->format('Ym').'-SN',
            'entry_count' => 1,
            'total_quantity' => 1,
            'total_estimated_cost_micros' => 15_000,
            'unknown_class_count' => 0,
            'billable_attempt_count' => 1,
            'recomputed_at' => $period,
        ]);
        $this->inc('usage_aggregates');
    }

    private function seedDecodeBlockedCursor(Office $office): void
    {
        // C09: cinco falhas consecutivas de decode → cursor BLOCKED, NSU preservado.
        $row = $this->clientMap['C09'] ?? null;
        if ($row === null) {
            return;
        }

        $lastNsu = 42;
        $cursor = SyncCursor::query()->create([
            'office_id' => $office->id,
            'establishment_id' => $row['establishment']->id,
            'environment' => 'restricted_production',
            'last_nsu' => $lastNsu,
            'status' => SyncCursorStatus::Blocked,
            'consecutive_decode_failures' => 5,
            'attempts' => 5,
            'next_sync_at' => null,
            'last_success_at' => $this->anchor->subDays(3),
            'last_error' => $this->marker.' 5 falhas consecutivas de decode Base64/GZip (simulado). NSU preservado='.$lastNsu,
        ]);
        $this->inc('sync_cursors');

        for ($i = 1; $i <= 5; $i++) {
            SyncRun::query()->create([
                'office_id' => $office->id,
                'sync_cursor_id' => $cursor->id,
                'status' => 'FAILED',
                'trigger' => $i === 5 ? 'MANUAL' : 'SCHEDULED',
                'triggered_by' => null,
                'pages_processed' => 1,
                'documents_persisted' => 0,
                'from_nsu' => $lastNsu,
                'to_nsu' => $lastNsu, // sem avanço
                'error_message' => $this->marker." decode failure #{$i}",
                'started_at' => $this->anchor->subHours(6 - $i),
                'finished_at' => $this->anchor->subHours(6 - $i)->addSeconds(20),
            ]);
            $this->inc('sync_runs');
        }
    }

    private function seedSentinelOffice(): void
    {
        $slug = $this->guard->sentinelOfficeSlug();
        $sentinel = Office::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => 'Office Sentinela Demo', 'is_active' => true],
        );

        // Mesmo root CNPJ de C01, office distinto — nunca aparece na carteira demo.
        $shared = $this->manifest->clients()[0];
        $exists = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $sentinel->id)
            ->where('notes', 'like', '%'.$this->marker.'%')
            ->exists();
        if ($exists) {
            return;
        }

        $client = Client::query()->create([
            'office_id' => $sentinel->id,
            'legal_name' => 'Sentinela Isolamento '.$shared['legal_name'],
            'display_name' => 'Sentinela',
            'root_cnpj' => strtoupper($shared['root_cnpj']),
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
            'notes' => $this->marker.' SENTINEL shared CNPJ with demo C01 — isolamento multi-office',
            'is_active' => true,
            'registration_source' => RegistrationSource::Legacy,
        ]);

        $cnpj = EstablishmentFactory::cnpjWithRoot($shared['root_cnpj'], '0001');
        Establishment::query()->create([
            'office_id' => $sentinel->id,
            'client_id' => $client->id,
            'cnpj' => $cnpj,
            'trade_name' => 'Sentinela Matriz',
            'is_matrix' => true,
            'is_active' => true,
            'capture_enabled' => false,
            'registration_status' => RegistrationStatus::Unknown,
            'registration_source' => RegistrationSource::Legacy,
        ]);

        $cat = $this->categories['SITFIS'] ?? FiscalCategory::query()->first();
        if ($cat !== null) {
            $run = FiscalMonitoringRun::query()->create([
                'office_id' => $sentinel->id,
                'client_id' => $client->id,
                'fiscal_category_id' => $cat->id,
                'system_code' => 'INTEGRA_SITFIS',
                'service_code' => 'SITFIS',
                'operation_code' => 'MONITOR',
                'trigger' => FiscalTrigger::Manual,
                'idempotency_key' => $this->prefix.'SENTINEL-RUN-'.$this->version,
                'status' => FiscalRunStatus::Completed,
                'result' => FiscalRunResult::Success,
                'situation' => FiscalSituation::UpToDate,
                'coverage' => FiscalCoverage::Full,
                'mutability' => FiscalMutability::ReadOnly,
                'correlation_id' => $this->corr('sentinel.run'),
                'started_at' => $this->anchor->subHour(),
                'finished_at' => $this->anchor->subHour()->addMinutes(2),
            ]);
            FiscalSnapshot::query()->create([
                'office_id' => $sentinel->id,
                'run_id' => $run->id,
                'client_id' => $client->id,
                'system_code' => 'INTEGRA_SITFIS',
                'service_code' => 'SITFIS',
                'operation_code' => 'MONITOR',
                'situation' => FiscalSituation::UpToDate,
                'coverage' => FiscalCoverage::Full,
                'version' => 1,
                'is_current' => true,
                'normalized' => ['demo_fixture' => true, 'sentinel' => true],
                'observed_at' => $this->anchor->subHour(),
                'created_at' => $this->anchor->subHour(),
            ]);
        }
        $this->inc('sentinel_clients');
    }

    private function createRun(
        Office $office,
        Client $client,
        FiscalCategory $cat,
        ?FiscalCompetence $competence,
        FiscalSituation $situation,
        FiscalRunStatus $status,
        ?FiscalRunResult $result,
        string $logicalKey,
        ?array $progress = null,
    ): FiscalMonitoringRun {
        $run = FiscalMonitoringRun::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'fiscal_category_id' => $cat->id,
            'competence_id' => $competence?->id,
            'system_code' => $cat->system_code ?? 'INTEGRA_CONTADOR',
            'service_code' => $cat->service_code ?? $cat->code,
            'operation_code' => 'MONITOR',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => $this->prefix.$this->version.'.'.$logicalKey,
            'status' => $status,
            'result' => $result,
            'situation' => $situation,
            'coverage' => $this->coverageOf($cat),
            'mutability' => FiscalMutability::ReadOnly,
            'correlation_id' => $this->corr($logicalKey),
            'progress' => $progress,
            'items_processed' => $status === FiscalRunStatus::Completed ? 1 : 0,
            'pages_processed' => $status === FiscalRunStatus::Completed ? 1 : 0,
            'error_code' => $situation === FiscalSituation::Error ? 'DEMO_ERROR' : null,
            'error_message' => $situation === FiscalSituation::Error
                ? $this->marker.' falha sintética de monitoramento'
                : ($situation === FiscalSituation::Blocked
                    ? $this->marker.' bloqueio sintético'
                    : null),
            'started_at' => $this->anchor->subHours(2),
            'finished_at' => $status === FiscalRunStatus::Running ? null : $this->anchor->subHours(1),
        ]);
        $this->inc('runs');

        return $run;
    }

    private function storeEvidence(FiscalMonitoringRun $run, string $logicalKey, array $payload = []): FiscalEvidenceArtifact
    {
        $stored = $this->vault->putEvidenceJson((int) $run->office_id, $logicalKey, $payload);

        $artifact = FiscalEvidenceArtifact::query()->create([
            'office_id' => $run->office_id,
            'run_id' => $run->id,
            'vault_object_id' => $stored['vault_object_id'],
            'content_sha256' => $stored['content_sha256'],
            'content_type' => $stored['content_type'],
            'byte_size' => $stored['byte_size'],
            'source' => 'DEMO_FIXTURE',
            'source_version' => $this->version,
            'observed_at' => $this->anchor->subHour(),
            'retention_until' => $this->anchor->addYears(7),
            'is_immutable' => true,
            'metadata' => $this->meta($logicalKey, ['system_code' => $run->system_code]),
            'created_at' => $this->anchor->subHour(),
        ]);
        $this->inc('evidences');

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function meta(string $logicalKey, array $extra = []): array
    {
        return $this->content->metadata($logicalKey, $extra);
    }

    private function corr(string $logicalKey): string
    {
        return $this->prefix.$this->version.'.'.str_replace(' ', '_', $logicalKey);
    }

    private function inc(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    private function coverageOf(FiscalCategory $cat): FiscalCoverage
    {
        $raw = $cat->default_coverage;
        if ($raw instanceof FiscalCoverage) {
            return $raw;
        }

        return FiscalCoverage::tryFrom((string) $raw) ?? FiscalCoverage::Partial;
    }
}
