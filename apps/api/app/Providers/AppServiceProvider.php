<?php

namespace App\Providers;

use App\Actions\Communication\CreateCommunicationMessageAction;
use App\Contracts\AdnContributorClient;
use App\Contracts\AssistantLlmGateway;
use App\Contracts\AutenticarProcuradorClient;
use App\Contracts\CaixaPostalClient;
use App\Contracts\CaixaPostalIndicatorClient;
use App\Contracts\CnpjRegistrationLookup;
use App\Contracts\CommunicationOutboundMessageWriter;
use App\Contracts\CommunicationProfilePictureDownloader;
use App\Contracts\CommunicationTransport;
use App\Contracts\CteXmlSignatureValidator;
use App\Contracts\DteIndicatorClient;
use App\Contracts\EnsuresClientProcuracaoForConsult;
use App\Contracts\EsocialBxCurlRuntime;
use App\Contracts\EsocialBxSoapTransport;
use App\Contracts\EsocialEventClient;
use App\Contracts\FgtsDigitalPortalClient;
use App\Contracts\FiscalMutationTransport;
use App\Contracts\GuideEmissionClient;
use App\Contracts\IntegraContadorClient;
use App\Contracts\IntegraEligibilityEvaluating;
use App\Contracts\IntegraProcuracoesClient;
use App\Contracts\MaOutboundXmlRetrievalClient;
use App\Contracts\OutboundXmlCaptureCapacityPlanner;
use App\Contracts\ParcelamentoSource;
use App\Contracts\PfxReaderInterface;
use App\Contracts\ResolvesSerproCapabilityDriver;
use App\Contracts\SecureObjectStore;
use App\Contracts\SefazCteDistDfeClient;
use App\Contracts\SefazDistDfeClient;
use App\Contracts\SefazNfeManifestationClient;
use App\Contracts\SefazOutboundInutilizationClient;
use App\Contracts\SefazOutboundMutatingProbeClient;
use App\Contracts\SefazOutboundProtocolQueryClient;
use App\Contracts\SerproContractAuthenticator;
use App\Contracts\SerproFiscalMutationTransport;
use App\Contracts\SerproOperationExecutor;
use App\Contracts\SitfisIdentityResolving;
use App\Contracts\SitfisPdfTextExtracting;
use App\Contracts\SvrsNfceDownloadResponseParser as SvrsNfceDownloadResponseParserContract;
use App\Contracts\SvrsNfceOutboundXmlRetrievalClient;
use App\Contracts\SvrsNfe55OutboundXmlRetrievalClient;
use App\Contracts\SvrsPortalEgressGovernor;
use App\Contracts\TaxGuideEnrollment;
use App\Enums\SerproCapabilityDriver;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientContact;
use App\Models\ClientCredential;
use App\Models\Establishment;
use App\Models\OutboundCaptureProfile;
use App\Models\SavedListFilter;
use App\Models\TenantCredential;
use App\Models\TenantFiscalIdentity;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Models\WorkExport;
use App\Models\WorkProcess;
use App\Models\WorkProcessTemplate;
use App\Models\WorkTask;
use App\Policies\ClientCategoryPolicy;
use App\Policies\ClientContactPolicy;
use App\Policies\ClientCredentialPolicy;
use App\Policies\ClientPolicy;
use App\Policies\EstablishmentPolicy;
use App\Policies\OutboundCaptureProfilePolicy;
use App\Policies\SavedListFilterPolicy;
use App\Policies\TenantFiscalCredentialPolicy;
use App\Policies\TenantSettingsPolicy;
use App\Policies\Work\WorkDepartmentPolicy;
use App\Policies\Work\WorkExportPolicy;
use App\Policies\Work\WorkProcessPolicy;
use App\Policies\Work\WorkProcessTemplatePolicy;
use App\Policies\Work\WorkTaskPolicy;
use App\Services\Adn\CurlMtlsTransport;
use App\Services\Adn\HttpAdnContributorClient;
use App\Services\Assistant\OpenAiAssistantLlmGateway;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Certificates\PfxReader;
use App\Services\Clients\CcmeiDadosFetcher;
use App\Services\Clients\CcmeiRegistrationEnricher;
use App\Services\Clients\CnpjWsRegistrationLookup;
use App\Services\Clients\NullCcmeiDadosFetcher;
use App\Services\Clients\RegistrationLookupMerger;
use App\Services\Clients\RegistrationLookupOrchestrator;
use App\Services\Clients\SerproConsultaCnpjLookup;
use App\Services\Communication\Media\CommunicationMediaStore;
use App\Services\Communication\ProfilePicture\CurlCommunicationProfilePictureDownloader;
use App\Services\Communication\Transport\HttpCommunicationTransport;
use App\Services\Esocial\CurlEsocialBxSoapTransport;
use App\Services\Esocial\DisabledEsocialEventClient;
use App\Services\Esocial\FgtsEsocialSourceAdapter;
use App\Services\Esocial\HttpEsocialBxEventClient;
use App\Services\Esocial\NativeEsocialBxCurlRuntime;
use App\Services\FgtsDigital\Clients\DisabledFgtsDigitalPortalClient;
use App\Services\FgtsDigital\Clients\FixtureFgtsDigitalPortalClient;
use App\Services\FgtsDigital\Clients\ProcessFgtsDigitalPortalClient;
use App\Services\Fiscal\Guides\PagtowebArrecadacaoReceiptAdapter;
use App\Services\Fiscal\Guides\PagtowebPaymentCountAdapter;
use App\Services\Fiscal\Guides\PagtowebPaymentListAdapter;
use App\Services\Fiscal\Guides\SerproGuideEmissionClient;
use App\Services\Fiscal\Guides\SicalcRevenueSupportAdapter;
use App\Services\Fiscal\ManualConsult\ManualConsultExecutionContext;
use App\Services\Fiscal\Mutations\IntegraFiscalMutationTransport;
use App\Services\Fiscal\SimplesMei\CcmeiPostConsultService;
use App\Services\Fiscal\SimplesMei\CcmeiRegistrationStatusPostConsultService;
use App\Services\Fiscal\SimplesMei\DefisDeclarationProjector;
use App\Services\Fiscal\SimplesMei\DefisDeclarationReferenceStore;
use App\Services\Fiscal\SimplesMei\DefisLatestDeclarationPostConsultService;
use App\Services\Fiscal\SimplesMei\DefisSpecificDeclarationPostConsultService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdConsDeclaracao13Codec;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdDocumentCodecs;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPostConsultService;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiDividaAtiva24Codec;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiPostConsultService;
use App\Services\Fiscal\SimplesMei\RegimeApplicabilityService;
use App\Services\Fiscal\SimplesMei\RegimeResolutionCodec;
use App\Services\Fiscal\SimplesMei\RegimeResolutionPostConsultService;
use App\Services\Fiscal\SimplesMei\SimplesMeiAdapter;
use App\Services\Fiscal\SimplesMei\SimplesMeiCatalog;
use App\Services\Fiscal\SimplesMei\SimplesMeiResponseMapper;
use App\Services\FiscalMonitoring\FiscalAdapterRegistry;
use App\Services\Integra\CapabilityAwareIntegraContadorClient;
use App\Services\Integra\ContributorCnpjResolver;
use App\Services\Integra\Dctfweb\DctfwebAdapterRegistrar;
use App\Services\Integra\DisabledAutenticarProcuradorClient;
use App\Services\Integra\DisabledIntegraProcuracoesClient;
use App\Services\Integra\EnsureClientProcuracaoForConsult;
use App\Services\Integra\FixtureIntegraProcuracoesClient;
use App\Services\Integra\HttpAutenticarProcuradorClient;
use App\Services\Integra\HttpIntegraProcuracoesClient;
use App\Services\Integra\IntegraEligibilityService;
use App\Services\Integra\Mailbox\CaixaPostalDetailAdapter;
use App\Services\Integra\Mailbox\CaixaPostalIndicatorAdapter;
use App\Services\Integra\Mailbox\CaixaPostalListAdapter;
use App\Services\Integra\Mailbox\DteIndicatorAdapter;
use App\Services\Integra\Mailbox\SerproCaixaPostalClient;
use App\Services\Integra\Mailbox\SerproCaixaPostalIndicatorClient;
use App\Services\Integra\Mailbox\SerproDteIndicatorClient;
use App\Services\Integra\Parcelamento\ParcelamentoEmitDocumentAdapter;
use App\Services\Integra\Parcelamento\ParcelamentoMutatingAdapter;
use App\Services\Integra\Parcelamento\ParcelamentoReadAdapter;
use App\Services\Integra\Parcelamento\SerproParcelamentoSource;
use App\Services\Integra\Parcelamento\StubTaxGuideEnrollment;
use App\Services\Integra\Sitfis\SitfisIdentityResolver;
use App\Services\Integra\Sitfis\SitfisSourceAdapter;
use App\Services\Integra\Sitfis\SmalotSitfisPdfTextExtractor;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Services\MeiAutomation\MeiAutomationAttemptRepository;
use App\Services\MeiAutomation\MeiPortalFiscalMutationTransport;
use App\Services\MeiAutomation\MeiProviderPolicy;
use App\Services\MeiAutomation\MeiProviderRouter;
use App\Services\MeiAutomation\Providers\ReceitaPortalProvider;
use App\Services\MeiAutomation\Providers\SerproMeiProvider;
use App\Services\Outbound\DisabledMaOutboundXmlRetrievalClient;
use App\Services\Outbound\DisabledSefazOutboundInutilizationClient;
use App\Services\Outbound\DisabledSefazOutboundMutatingProbeClient;
use App\Services\Outbound\DisabledSvrsNfceOutboundXmlRetrievalClient;
use App\Services\Outbound\DisabledSvrsNfe55OutboundXmlRetrievalClient;
use App\Services\Outbound\HttpSefazOutboundProtocolQueryClient;
use App\Services\Outbound\HttpSvrsNfceOutboundXmlRetrievalClient;
use App\Services\Outbound\HttpSvrsNfe55OutboundXmlRetrievalClient;
use App\Services\Outbound\ProtocolQueryResponseParser;
use App\Services\Outbound\RedisSvrsPortalEgressGovernor;
use App\Services\Outbound\SvrsNfceConfig;
use App\Services\Outbound\SvrsNfceDownloadResponseParser;
use App\Services\Outbound\SvrsNfceKillSwitchService;
use App\Services\Outbound\SvrsNfe55Config;
use App\Services\Outbound\SvrsNfe55KillSwitchService;
use App\Services\Outbound\SvrsPortalEgressConfig;
use App\Services\Platform\TenantSubscriptionGate;
use App\Services\Sefaz\DistDfeResponseParser;
use App\Services\Sefaz\HttpSefazCteDistDfeClient;
use App\Services\Sefaz\HttpSefazDistDfeClient;
use App\Services\Sefaz\HttpSefazNfeManifestationClient;
use App\Services\Sefaz\ManifestationResponseParser;
use App\Services\Sefaz\SpedCommonCteXmlSignatureValidator;
use App\Services\Serpro\CapabilityDriverResolver;
use App\Services\Serpro\Catalog\OfficialServiceCatalogImporter;
use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;
use App\Services\Serpro\Catalog\OperationCoordinateResolver;
use App\Services\Serpro\HttpSerproContractAuthenticator;
use App\Services\Serpro\SerproContractService;
use App\Services\Serpro\SerproHttpTransport;
use App\Services\Serpro\SerproOperationService;
use App\Services\Serpro\SerproProductionBootGuard;
use App\Services\Vault\EnvelopeCrypto;
use App\Services\Vault\FilesystemSecureObjectStore;
use App\Support\CurrentTenant;
use App\Support\FiscalDataModel\PrivilegedTenantContext;
use App\Support\MultitenantRbac\EffectivePermissionsResolver;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentTenant::class, fn () => new CurrentTenant);
        $this->app->scoped(
            TenantAuthorization::class,
            fn ($app) => new TenantAuthorization(
                $app->make(CurrentTenant::class),
                $app->make(EffectivePermissionsResolver::class),
            ),
        );
        $this->app->scoped(ManualConsultExecutionContext::class);

        $this->app->singleton(EnvelopeCrypto::class, function () {
            $masterKey = (string) config('vault.master_key', '');

            // Fail-fast fora de testing: sem chave efêmera (FPM/Horizon precisam da mesma master key)
            if ($masterKey === '' && ! $this->app->environment('testing')) {
                throw new \RuntimeException(
                    'VAULT_MASTER_KEY não configurada. Defina em apps/api/.env (32 bytes em base64).'
                );
            }

            if ($masterKey === '' && $this->app->environment('testing')) {
                config(['vault.master_key' => base64_encode(random_bytes(32))]);
            }

            return EnvelopeCrypto::fromConfig();
        });

        $this->app->singleton(SecureObjectStore::class, function ($app) {
            return new FilesystemSecureObjectStore(
                $app->make(EnvelopeCrypto::class),
                (string) config('vault.disk_root'),
            );
        });

        $this->app->singleton(CommunicationMediaStore::class, function ($app) {
            return new CommunicationMediaStore(
                $app->make(EnvelopeCrypto::class),
                (string) config('communication.media.disk_root'),
            );
        });
        $this->app->bind(CommunicationProfilePictureDownloader::class, CurlCommunicationProfilePictureDownloader::class);
        $this->app->bind(CommunicationOutboundMessageWriter::class, CreateCommunicationMessageAction::class);
        $this->app->bind(CommunicationTransport::class, HttpCommunicationTransport::class);
        $this->app->bind(AssistantLlmGateway::class, OpenAiAssistantLlmGateway::class);

        $this->app->singleton(CurlMtlsTransport::class, function () {
            return new CurlMtlsTransport(
                timeoutSeconds: (int) config('adn.timeout_seconds', 30),
                connectTimeoutSeconds: (int) config('adn.connect_timeout_seconds', 10),
                verifyTls: (bool) config('adn.verify_tls', true),
            );
        });

        $this->app->singleton(AdnContributorClient::class, function ($app) {
            return new HttpAdnContributorClient(
                $app->make(CurlMtlsTransport::class),
                (string) config('adn.base_url'),
            );
        });

        $this->app->singleton(DistDfeResponseParser::class);
        $this->app->singleton(SefazDistDfeClient::class, function ($app) {
            return new HttpSefazDistDfeClient(
                $app->make(CurlMtlsTransport::class),
                $app->make(DistDfeResponseParser::class),
            );
        });
        $this->app->singleton(SefazCteDistDfeClient::class, function ($app) {
            return new HttpSefazCteDistDfeClient(
                $app->make(CurlMtlsTransport::class),
                $app->make(DistDfeResponseParser::class),
            );
        });
        $this->app->singleton(CteXmlSignatureValidator::class, SpedCommonCteXmlSignatureValidator::class);

        $this->app->singleton(ManifestationResponseParser::class);
        $this->app->singleton(SefazNfeManifestationClient::class, function ($app) {
            return new HttpSefazNfeManifestationClient(
                $app->make(CurlMtlsTransport::class),
                $app->make(ManifestationResponseParser::class),
            );
        });

        $this->app->singleton(PfxReaderInterface::class, PfxReader::class);
        $this->app->singleton(CnpjWsRegistrationLookup::class);
        $this->app->singleton(SerproConsultaCnpjLookup::class);
        $this->app->singleton(RegistrationLookupMerger::class);
        $this->app->singleton(CcmeiDadosFetcher::class, NullCcmeiDadosFetcher::class);
        $this->app->singleton(CcmeiRegistrationEnricher::class);
        $this->app->singleton(RegistrationLookupOrchestrator::class);
        $this->app->singleton(CnpjRegistrationLookup::class, RegistrationLookupOrchestrator::class);

        // MA outbound — defaults seguros (M2M/mutação desabilitados; consulta HTTP real)
        $this->app->singleton(ProtocolQueryResponseParser::class);
        $this->app->singleton(SefazOutboundProtocolQueryClient::class, function ($app) {
            return new HttpSefazOutboundProtocolQueryClient(
                $app->make(CurlMtlsTransport::class),
                $app->make(ProtocolQueryResponseParser::class),
            );
        });
        $this->app->singleton(MaOutboundXmlRetrievalClient::class, DisabledMaOutboundXmlRetrievalClient::class);
        $this->app->singleton(SefazOutboundInutilizationClient::class, DisabledSefazOutboundInutilizationClient::class);
        $this->app->singleton(SefazOutboundMutatingProbeClient::class, DisabledSefazOutboundMutatingProbeClient::class);

        // SVRS portal egress (compartilhado NF-e 55 + NFC-e 65) — fail-closed
        $this->app->singleton(SvrsPortalEgressConfig::class);
        $this->app->singleton(SvrsPortalEgressGovernor::class, RedisSvrsPortalEgressGovernor::class);

        // Agendamento por prazo — capacidade lê o governador (sem PFX)
        $this->app->singleton(OutboundXmlCaptureCapacityPlanner::class, \App\Services\Outbound\OutboundXmlCaptureCapacityPlanner::class);

        // SVRS NFC-e XML retrieval — default disabled client unless flag on
        $this->app->singleton(SvrsNfceConfig::class);
        $this->app->singleton(SvrsNfceKillSwitchService::class);
        $this->app->singleton(SvrsNfceDownloadResponseParserContract::class, SvrsNfceDownloadResponseParser::class);
        $this->app->singleton(SvrsNfceDownloadResponseParser::class);
        // Factory por resolução (não singleton): flag pode mudar sem restart do worker
        $this->app->bind(SvrsNfceOutboundXmlRetrievalClient::class, function ($app) {
            if (! (bool) config('sefaz.svrs_nfce_xml.retrieval_enabled', false)) {
                return $app->make(DisabledSvrsNfceOutboundXmlRetrievalClient::class);
            }

            return $app->make(HttpSvrsNfceOutboundXmlRetrievalClient::class);
        });

        // SVRS NF-e 55 — default desabilitado até smoke G13
        $this->app->singleton(SvrsNfe55Config::class);
        $this->app->singleton(SvrsNfe55KillSwitchService::class);
        $this->app->bind(SvrsNfe55OutboundXmlRetrievalClient::class, function ($app) {
            if (! (bool) config('sefaz.svrs_nfe55_xml.retrieval_enabled', false)) {
                return $app->make(DisabledSvrsNfe55OutboundXmlRetrievalClient::class);
            }

            return $app->make(HttpSvrsNfe55OutboundXmlRetrievalClient::class);
        });

        // SERPRO / Integra Contador — catálogo, drivers e transporte oficial
        $this->app->singleton(SerproHttpTransport::class, function () {
            return new SerproHttpTransport(
                timeoutSeconds: (int) config('serpro.api.timeout_seconds', 60),
                connectTimeoutSeconds: (int) config('serpro.api.connect_timeout_seconds', 10),
                verifyTls: (bool) config('serpro.api.verify_tls', true),
            );
        });

        $this->app->singleton(OfficialServiceCatalogManifest::class);
        $this->app->singleton(OfficialServiceCatalogImporter::class);
        $this->app->singleton(OperationCoordinateResolver::class);
        $this->app->singleton(CapabilityDriverResolver::class);
        $this->app->singleton(ResolvesSerproCapabilityDriver::class, CapabilityDriverResolver::class);
        $this->app->singleton(IntegraEligibilityEvaluating::class, IntegraEligibilityService::class);
        $this->app->singleton(SitfisIdentityResolving::class, SitfisIdentityResolver::class);
        $this->app->singleton(SitfisPdfTextExtracting::class, SmalotSitfisPdfTextExtractor::class);
        $this->app->singleton(EnsuresClientProcuracaoForConsult::class, EnsureClientProcuracaoForConsult::class);
        $this->app->bind(SerproContractAuthenticator::class, HttpSerproContractAuthenticator::class);
        $this->app->bind(IntegraContadorClient::class, CapabilityAwareIntegraContadorClient::class);

        // Único entrypoint produtivo — adapters/jobs injetam isto, não o client HTTP.
        $this->app->singleton(SerproOperationExecutor::class, SerproOperationService::class);
        $this->app->singleton(SerproOperationService::class);

        $this->app->bind(AutenticarProcuradorClient::class, function ($app) {
            $driver = $app->make(CapabilityDriverResolver::class)->forCapability('autentica_procurador');

            return match ($driver) {
                SerproCapabilityDriver::Disabled,
                SerproCapabilityDriver::Fixture => $app->make(DisabledAutenticarProcuradorClient::class),
                SerproCapabilityDriver::Real => $app->make(HttpAutenticarProcuradorClient::class),
            };
        });
        $this->app->bind(IntegraProcuracoesClient::class, function ($app) {
            $driver = $app->make(CapabilityDriverResolver::class)->forCapability('authorization');

            return match ($driver) {
                SerproCapabilityDriver::Disabled => $app->make(DisabledIntegraProcuracoesClient::class),
                SerproCapabilityDriver::Fixture => $app->make(FixtureIntegraProcuracoesClient::class),
                SerproCapabilityDriver::Real => $app->make(HttpIntegraProcuracoesClient::class),
            };
        });

        // Núcleo fiscal — registry de adapters (módulos filhos registram em boot de seus providers)
        $this->app->singleton(FiscalAdapterRegistry::class);

        // FGTS / eSocial BX — provider oficial somente por opt-in; default fail-closed.
        $this->app->singleton(DisabledEsocialEventClient::class);
        $this->app->bind(EsocialBxCurlRuntime::class, NativeEsocialBxCurlRuntime::class);
        $this->app->bind(EsocialBxSoapTransport::class, CurlEsocialBxSoapTransport::class);
        $this->app->bind(EsocialEventClient::class, function ($app) {
            return match ((string) config('fgts_esocial.driver', 'disabled')) {
                'official_bx' => $app->make(HttpEsocialBxEventClient::class),
                'disabled' => $app->make(DisabledEsocialEventClient::class),
                default => new DisabledEsocialEventClient(
                    errorCode: 'ESOCIAL_BX_DRIVER_INVALID',
                    message: 'Driver eSocial BX inválido; integração bloqueada.',
                ),
            };
        });

        // FGTS Digital portal — browser apenas por opt-in; fixture nunca realiza rede.
        $this->app->bind(FgtsDigitalPortalClient::class, function ($app) {
            return match ((string) config('fgts_digital.driver', 'disabled')) {
                'fixture' => $app->make(FixtureFgtsDigitalPortalClient::class),
                'portal_browser' => $app->make(ProcessFgtsDigitalPortalClient::class),
                default => $app->make(DisabledFgtsDigitalPortalClient::class),
            };
        });

        // Caixa Postal / DTE — o runtime só resolve clientes SERPRO reais.
        $this->app->bind(CaixaPostalClient::class, SerproCaixaPostalClient::class);
        $this->app->bind(CaixaPostalIndicatorClient::class, SerproCaixaPostalIndicatorClient::class);
        $this->app->bind(DteIndicatorClient::class, SerproDteIndicatorClient::class);

        // Mutações fiscais — transporte oficial; doubles são registrados apenas em tests/Support.
        $this->app->bind(FiscalMutationTransport::class, MeiPortalFiscalMutationTransport::class);
        $this->app->bind(SerproFiscalMutationTransport::class, IntegraFiscalMutationTransport::class);

        // Guias fiscais — o adapter SERPRO falha fechado quando a capability não for real.
        $this->app->singleton(SerproGuideEmissionClient::class);
        $this->app->bind(GuideEmissionClient::class, SerproGuideEmissionClient::class);

        // Parcelamentos SN/MEI — disabled falha fechado; sem fallback local.
        $this->app->singleton(SerproParcelamentoSource::class);
        $this->app->bind(ParcelamentoSource::class, SerproParcelamentoSource::class);
        $this->app->singleton(TaxGuideEnrollment::class, StubTaxGuideEnrollment::class);
        $this->app->singleton(ParcelamentoReadAdapter::class);
        $this->app->singleton(ParcelamentoEmitDocumentAdapter::class);
        $this->app->singleton(ParcelamentoMutatingAdapter::class);
    }

    public function boot(): void
    {
        FormRequest::failOnUnknownFields($this->app->environment('local', 'testing'));

        // Fail closed em dev/test: N+1 vira exceção; produção permanece permissiva.
        Model::preventLazyLoading($this->app->environment('local', 'testing'));

        $this->registerPrivilegedTenantContextListeners();

        // Preflight: simulated proibido em production
        if ($this->app->environment('production')) {
            try {
                $this->app->make(CapabilityDriverResolver::class)->assertProductionSafe();
                $this->app->make(SerproProductionBootGuard::class)->assertSafeOrFail();
            } catch (\Throwable $e) {
                // Fail-closed no boot de produção
                throw $e;
            }
        }

        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(ClientCategory::class, ClientCategoryPolicy::class);
        Gate::policy(Establishment::class, EstablishmentPolicy::class);
        Gate::policy(ClientCredential::class, ClientCredentialPolicy::class);
        Gate::policy(ClientContact::class, ClientContactPolicy::class);
        Gate::policy(OutboundCaptureProfile::class, OutboundCaptureProfilePolicy::class);
        Gate::policy(TenantFiscalIdentity::class, TenantFiscalCredentialPolicy::class);
        Gate::policy(TenantCredential::class, TenantFiscalCredentialPolicy::class);
        Gate::policy(SavedListFilter::class, SavedListFilterPolicy::class);
        Gate::define('tenant-settings.view', [TenantSettingsPolicy::class, 'view']);
        Gate::define('tenant-settings.manage', [TenantSettingsPolicy::class, 'manage']);

        // Módulo operacional (Work) — plano de dados tenant-scoped
        Gate::policy(WorkDepartment::class, WorkDepartmentPolicy::class);
        Gate::policy(WorkProcessTemplate::class, WorkProcessTemplatePolicy::class);
        Gate::policy(WorkProcess::class, WorkProcessPolicy::class);
        Gate::policy(WorkTask::class, WorkTaskPolicy::class);
        Gate::policy(WorkExport::class, WorkExportPolicy::class);

        // PLATFORM_ADMIN é global e separado do papel e das permissões do tenant.
        // NÃO concede leitura fiscal implícita.
        Gate::define('platform-admin', function (User $user): bool {
            return $user->is_active && $user->isPlatformAdmin();
        });

        // Mutações no tenant atual exigem assinatura operacional (TRIAL/ACTIVE/PAST_DUE).
        Gate::define('tenant-subscription-writable', function (User $user): bool {
            if (! $user->is_active) {
                return false;
            }

            return app(TenantSubscriptionGate::class)->allowsMutations();
        });

        Gate::define('tenant-subscription-external', function (User $user): bool {
            if (! $user->is_active) {
                return false;
            }

            return app(TenantSubscriptionGate::class)->allowsExternalCalls();
        });

        // Adapters de módulos fiscais no registry do núcleo
        $registry = $this->app->make(FiscalAdapterRegistry::class);
        $registry->register($this->app->make(SitfisSourceAdapter::class));
        $registry->register($this->app->make(FgtsEsocialSourceAdapter::class));
        $registry->register($this->app->make(CaixaPostalListAdapter::class));
        $registry->register($this->app->make(CaixaPostalDetailAdapter::class));
        $registry->register($this->app->make(CaixaPostalIndicatorAdapter::class));
        $registry->register($this->app->make(DteIndicatorAdapter::class));
        $registry->register($this->app->make(SicalcRevenueSupportAdapter::class));
        $registry->register($this->app->make(PagtowebPaymentCountAdapter::class));
        $registry->register($this->app->make(PagtowebPaymentListAdapter::class));
        $registry->register($this->app->make(PagtowebArrecadacaoReceiptAdapter::class));

        // Integra-SN / Integra-MEI — um adapter por operação do catálogo
        foreach (SimplesMeiCatalog::all() as $def) {
            $serproAdapter = new SimplesMeiAdapter(
                definition: $def,
                eligibility: $this->app->make(IntegraEligibilityService::class),
                operations: $this->app->make(SerproOperationService::class),
                mapper: $this->app->make(SimplesMeiResponseMapper::class),
                contracts: $this->app->make(SerproContractService::class),
                authorizations: $this->app->make(TenantSerproAuthorizationService::class),
                regimeApplicability: $this->app->make(RegimeApplicabilityService::class),
                contributors: $this->app->make(ContributorCnpjResolver::class),
                pgdasdCodec13: $this->app->make(PgdasdConsDeclaracao13Codec::class),
                pgdasdDocumentCodecs: $this->app->make(PgdasdDocumentCodecs::class),
                pgdasdPostConsult: $this->app->make(PgdasdPostConsultService::class),
                pgmeiCodec24: $this->app->make(PgmeiDividaAtiva24Codec::class),
                pgmeiPostConsult: $this->app->make(PgmeiPostConsultService::class),
                ccmeiPostConsult: $this->app->make(CcmeiPostConsultService::class),
                ccmeiRegistrationStatusPost: $this->app->make(CcmeiRegistrationStatusPostConsultService::class),
                regimeResolutionCodec: $this->app->make(RegimeResolutionCodec::class),
                regimeResolutionPost: $this->app->make(RegimeResolutionPostConsultService::class),
                defisProjector: $this->app->make(DefisDeclarationProjector::class),
                defisLatestDeclarationPost: $this->app->make(DefisLatestDeclarationPostConsultService::class),
                defisSpecificDeclarationPost: $this->app->make(DefisSpecificDeclarationPostConsultService::class),
                defisReferences: $this->app->make(DefisDeclarationReferenceStore::class),
                procuracaoEnsure: $this->app->make(EnsureClientProcuracaoForConsult::class),
            );

            $registry->register(strtoupper($def->systemCode) === 'INTEGRA_MEI'
                ? new MeiProviderRouter(
                    definition: $def,
                    serpro: new SerproMeiProvider($serproAdapter),
                    portal: $this->app->make(ReceitaPortalProvider::class),
                    policy: $this->app->make(MeiProviderPolicy::class),
                    attempts: $this->app->make(MeiAutomationAttemptRepository::class),
                )
                : $serproAdapter);
        }

        // Integra-DCTFWeb / MIT (adapters somente-leitura + mutantes atrás de flags OFF)
        $this->app->make(DctfwebAdapterRegistrar::class)
            ->register($registry);

        // Integra-Parcelamento — modalidades SN/MEI (leitura + emissão assistida + mutantes OFF)
        $registry->register($this->app->make(ParcelamentoReadAdapter::class));
        $registry->register($this->app->make(ParcelamentoEmitDocumentAdapter::class));
        $registry->register($this->app->make(ParcelamentoMutatingAdapter::class));
    }

    /**
     * Jobs e console operam sem membership HTTP: abrem contexto privilegiado tipado
     * para não reativar o anti-padrão "scope nulo = todos os tenants".
     */
    private function registerPrivilegedTenantContextListeners(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $name = $event->job->resolveName();
            PrivilegedTenantContext::enter('queue:'.$name);
        });

        Event::listen(JobProcessed::class, function (): void {
            PrivilegedTenantContext::leave();
        });

        Event::listen(JobFailed::class, function (): void {
            PrivilegedTenantContext::leave();
        });

        // JobExceptionOccurred não faz leave: JobFailed/JobProcessed fecham o ciclo.
        // Leave duplo em drivers sync com jobs aninhados zera o depth cedo demais.

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $command = (string) ($event->command ?? 'unknown');
            // Jobs e console multi-tenant devem filtrar tenant_id explicitamente
            // mesmo com contexto privilegiado aberto.
            if (PrivilegedTenantContext::isOpen()) {
                return;
            }
            PrivilegedTenantContext::enter('console:'.$command);
        });

        Event::listen(CommandFinished::class, function (): void {
            if (PrivilegedTenantContext::isOpen()) {
                PrivilegedTenantContext::leave();
            }
        });
    }
}
