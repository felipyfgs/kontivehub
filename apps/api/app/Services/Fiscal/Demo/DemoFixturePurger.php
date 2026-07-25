<?php

namespace App\Services\Fiscal\Demo;

use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\ClientTaxRegimePeriod;
use App\Models\DctfwebDarfDocument;
use App\Models\DctfwebDeclaration;
use App\Models\DctfwebEvidenceVersion;
use App\Models\DctfwebMutationAttempt;
use App\Models\EsocialEventEvidence;
use App\Models\Establishment;
use App\Models\FgtsCompetenceStatus;
use App\Models\FiscalCompetence;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalFinding;
use App\Models\FiscalGuideStub;
use App\Models\FiscalLastUpdateEvent;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalMonitoringSchedule;
use App\Models\FiscalPendingItem;
use App\Models\FiscalSnapshot;
use App\Models\MailboxAccessEvent;
use App\Models\MailboxAlert;
use App\Models\MailboxAttachment;
use App\Models\MailboxContributorState;
use App\Models\MailboxMessage;
use App\Models\MitApuracao;
use App\Models\Office;
use App\Models\OfficeFiscalCategoryLink;
use App\Models\SerproApiUsageEntry;
use App\Models\SerproApiUsageReservation;
use App\Models\SerproUsageMonthlyAggregate;
use App\Models\SyncCursor;
use App\Models\SyncRun;
use App\Models\TaxDeliveryEvidence;
use App\Models\TaxGuide;
use App\Models\TaxGuideDownloadToken;
use App\Models\TaxGuidePaymentConfirmation;
use App\Models\TaxGuideVersion;
use App\Models\TaxInstallmentOrder;
use App\Models\TaxInstallmentParcel;
use App\Models\TaxInstallmentPayment;
use App\Models\TaxObligationProjection;
use Illuminate\Support\Facades\Schema;

/**
 * Purga transacional e seletiva: somente registros demo do office configurado.
 * Nunca toca em outros tenants (incluindo sentinela).
 */
final class DemoFixturePurger
{
    public function __construct(
        private readonly DemoEnvironmentGuard $guard,
    ) {}

    /**
     * @return array{clients: int, office_id: int}
     */
    public function purgeDemoOffice(Office $office): array
    {
        $this->guard->assertCanSeed($office);

        $marker = $this->guard->fixtureMarker();
        $prefix = $this->guard->correlationPrefix();

        $clientIds = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('notes', 'like', '%'.$marker.'%')
            ->pluck('id');

        $clientIdList = $clientIds->all();

        // Null FKs cíclicos / opcionais antes de apagar filhos.
        if ($clientIds->isNotEmpty()) {
            $this->nullifyCircularFks($office->id, $clientIdList);
        }

        // Mailbox
        $this->deleteWhereOffice(MailboxAlert::class, $office->id, $clientIdList);
        $this->deleteWhereOffice(MailboxAccessEvent::class, $office->id, null, function ($q) use ($office) {
            $q->whereIn('mailbox_message_id', MailboxMessage::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where(function ($inner) {
                    $inner->where('external_id', 'like', 'DEMO_%')
                        ->orWhere('message_hash', 'like', 'demo%');
                })
                ->select('id'));
        });
        $this->deleteWhereOffice(MailboxAttachment::class, $office->id, null, function ($q) use ($office) {
            $q->whereIn('mailbox_message_id', MailboxMessage::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('external_id', 'like', 'DEMO_%')
                ->select('id'));
        });
        MailboxMessage::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('external_id', 'like', $prefix.'%')
            ->delete();
        // fallback: mensagens dos clients demo
        if ($clientIds->isNotEmpty()) {
            MailboxMessage::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->whereIn('client_id', $clientIdList)
                ->delete();
            MailboxContributorState::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->whereIn('client_id', $clientIdList)
                ->delete();
        }

        // Parcelamentos
        if ($clientIds->isNotEmpty()) {
            TaxInstallmentPayment::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            TaxInstallmentParcel::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            TaxInstallmentOrder::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
        }

        // Guias
        if ($clientIds->isNotEmpty()) {
            $guideIds = TaxGuide::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->pluck('id');
            if ($guideIds->isNotEmpty()) {
                TaxGuidePaymentConfirmation::query()->withoutGlobalScopes()
                    ->where('office_id', $office->id)->whereIn('tax_guide_id', $guideIds)->delete();
                $versionIds = TaxGuideVersion::query()->withoutGlobalScopes()
                    ->where('office_id', $office->id)->whereIn('tax_guide_id', $guideIds)->pluck('id');
                if ($versionIds->isNotEmpty()) {
                    TaxGuideDownloadToken::query()->withoutGlobalScopes()
                        ->where('office_id', $office->id)
                        ->whereIn('tax_guide_version_id', $versionIds)
                        ->delete();
                }
                TaxGuide::query()->withoutGlobalScopes()
                    ->whereIn('id', $guideIds)->update(['current_version_id' => null]);
                TaxGuideVersion::query()->withoutGlobalScopes()
                    ->where('office_id', $office->id)->whereIn('tax_guide_id', $guideIds)->delete();
                TaxGuide::query()->withoutGlobalScopes()->whereIn('id', $guideIds)->delete();
            }
        }

        // Declarações
        if ($clientIds->isNotEmpty()) {
            TaxDeliveryEvidence::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->whereIn('projection_id', TaxObligationProjection::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->whereIn('client_id', $clientIdList)
                    ->select('id'))
                ->delete();
            TaxObligationProjection::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
        }

        // FGTS / eSocial
        if ($clientIds->isNotEmpty()) {
            EsocialEventEvidence::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            FgtsCompetenceStatus::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
        }

        // DCTFWeb / MIT
        if ($clientIds->isNotEmpty()) {
            DctfwebMutationAttempt::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            DctfwebDarfDocument::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            DctfwebEvidenceVersion::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            DctfwebDeclaration::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            MitApuracao::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            FiscalGuideStub::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            ClientTaxRegimePeriod::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
        }

        // Núcleo fiscal
        if ($clientIds->isNotEmpty()) {
            FiscalPendingItem::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            FiscalFinding::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            FiscalSnapshot::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            FiscalEvidenceArtifact::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->whereIn('run_id', FiscalMonitoringRun::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->where(function ($q) use ($prefix, $clientIdList) {
                        $q->where('correlation_id', 'like', $prefix.'%')
                            ->orWhereIn('client_id', $clientIdList);
                    })
                    ->select('id'))
                ->delete();
            FiscalMonitoringRun::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where(function ($q) use ($prefix, $clientIdList) {
                    $q->where('correlation_id', 'like', $prefix.'%')
                        ->orWhereIn('client_id', $clientIdList);
                })
                ->delete();
            FiscalLastUpdateEvent::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where(function ($q) use ($prefix, $clientIdList) {
                    $q->where('event_external_id', 'like', $prefix.'%')
                        ->orWhereIn('client_id', $clientIdList);
                })
                ->delete();
            FiscalMonitoringSchedule::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            FiscalCompetence::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
            OfficeFiscalCategoryLink::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->whereIn('client_id', $clientIdList)->delete();
        }

        // Ledger consumo demo (sem contrato SERPRO sintético — só entradas shadow)
        SerproApiUsageEntry::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('correlation_id', 'like', $prefix.'%')
            ->delete();
        SerproApiUsageReservation::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('correlation_id', 'like', $prefix.'%')
            ->delete();
        SerproUsageMonthlyAggregate::query()
            ->where('office_id', $office->id)
            ->where('aggregate_key', 'like', $prefix.'%')
            ->delete();

        // Sync cursors (decode BLOCKED etc.) dos clients demo
        if ($clientIds->isNotEmpty()) {
            $estIds = Establishment::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->whereIn('client_id', $clientIdList)
                ->pluck('id');
            if ($estIds->isNotEmpty()) {
                $cursorIds = SyncCursor::query()->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->whereIn('establishment_id', $estIds)
                    ->pluck('id');
                SyncRun::query()->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->whereIn('sync_cursor_id', $cursorIds)
                    ->delete();
                SyncCursor::query()->withoutGlobalScopes()
                    ->whereIn('id', $cursorIds)
                    ->delete();
                ClientCredential::query()->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->whereIn('client_id', $clientIdList)
                    ->delete();
                Establishment::query()->withoutGlobalScopes()
                    ->whereIn('id', $estIds)
                    ->delete();
            }
            Client::query()->withoutGlobalScopes()
                ->whereIn('id', $clientIdList)
                ->delete();
        }

        return [
            'clients' => count($clientIdList),
            'office_id' => (int) $office->id,
        ];
    }

    /**
     * @param  list<int>  $clientIds
     */
    private function nullifyCircularFks(int $officeId, array $clientIds): void
    {
        if ($clientIds === []) {
            return;
        }

        TaxObligationProjection::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereIn('client_id', $clientIds)
            ->update(['conclusive_evidence_id' => null, 'evidence_artifact_id' => null]);

        DctfwebDeclaration::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereIn('client_id', $clientIds)
            ->update(['current_snapshot_id' => null]);

        MitApuracao::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereIn('client_id', $clientIds)
            ->update(['current_snapshot_id' => null]);

        FgtsCompetenceStatus::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereIn('client_id', $clientIds)
            ->update(['snapshot_id' => null, 'run_id' => null]);

        TaxInstallmentParcel::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereIn('client_id', $clientIds)
            ->update(['payment_id' => null, 'tax_guide_id' => null]);

        FiscalMonitoringRun::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereIn('client_id', $clientIds)
            ->update(['parent_run_id' => null, 'last_update_event_id' => null]);

        FiscalLastUpdateEvent::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereIn('client_id', $clientIds)
            ->update(['directed_run_id' => null]);

        TaxGuide::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereIn('client_id', $clientIds)
            ->update(['current_version_id' => null]);
    }

    /**
     * @param  class-string  $model
     * @param  list<int>|null  $clientIds
     */
    private function deleteWhereOffice(string $model, int $officeId, ?array $clientIds = null, ?callable $extra = null): void
    {
        if (! class_exists($model)) {
            return;
        }

        $q = $model::query()->withoutGlobalScopes()->where('office_id', $officeId);
        if ($clientIds !== null && $clientIds !== []) {
            if (Schema::hasColumn((new $model)->getTable(), 'client_id')) {
                $q->whereIn('client_id', $clientIds);
            }
        }
        if ($extra !== null) {
            $extra($q);
        }
        $q->delete();
    }
}
