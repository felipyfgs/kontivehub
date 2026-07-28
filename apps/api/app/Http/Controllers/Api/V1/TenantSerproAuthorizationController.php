<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenant\ConfigureTenantSerproAuthorAction;
use App\Actions\Tenant\DownloadTenantSerproTermDraftAction;
use App\Actions\Tenant\EvaluateTenantSerproEligibilityAction;
use App\Actions\Tenant\GenerateTenantSerproTermDraftAction;
use App\Actions\Tenant\ListTenantProxyPowersAction;
use App\Actions\Tenant\RefreshTenantSerproTokenAction;
use App\Actions\Tenant\RejectManualTenantProxyPowerAction;
use App\Actions\Tenant\ShowTenantSerproAuthorizationAction;
use App\Actions\Tenant\ShowTenantSerproHealthAction;
use App\Actions\Tenant\SignTenantSerproTermAction;
use App\Actions\Tenant\SyncTenantProxyPowersAction;
use App\Actions\Tenant\UploadTenantSerproTermAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ConfigureTenantSerproAuthorRequest;
use App\Http\Requests\Tenant\DownloadTenantSerproTermDraftRequest;
use App\Http\Requests\Tenant\EvaluateTenantSerproEligibilityRequest;
use App\Http\Requests\Tenant\GenerateTenantSerproTermDraftRequest;
use App\Http\Requests\Tenant\ListTenantProxyPowersRequest;
use App\Http\Requests\Tenant\RefreshTenantSerproTokenRequest;
use App\Http\Requests\Tenant\RejectManualTenantProxyPowerRequest;
use App\Http\Requests\Tenant\SignTenantSerproTermRequest;
use App\Http\Requests\Tenant\SyncTenantProxyPowersRequest;
use App\Http\Requests\Tenant\UploadTenantSerproTermRequest;
use App\Http\Requests\Tenant\ViewTenantSerproAuthorizationRequest;
use App\Http\Resources\TaxProxyPowerCollection;
use App\Http\Resources\TenantProxyPowerSyncResource;
use App\Http\Resources\TenantSerproAuthorizationOverviewResource;
use App\Http\Resources\TenantSerproAuthorizationResource;
use App\Http\Resources\TenantSerproEligibilityResource;
use App\Http\Resources\TenantSerproHealthResource;
use App\Http\Resources\TenantSerproTermDraftResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Onboarding tenant-scoped: Autor, Termo, procurações e saúde sanitizada.
 *
 * Nunca retorna XML fora do download dedicado, PFX ou tokens.
 */
final class TenantSerproAuthorizationController extends Controller
{
    public function show(
        ViewTenantSerproAuthorizationRequest $request,
        ShowTenantSerproAuthorizationAction $showAuthorization,
    ): JsonResponse {
        return TenantSerproAuthorizationOverviewResource::make(
            $showAuthorization($request->environment()),
        )->response();
    }

    public function configureAuthor(
        ConfigureTenantSerproAuthorRequest $request,
        ConfigureTenantSerproAuthorAction $configureAuthor,
    ): JsonResponse {
        return TenantSerproAuthorizationResource::make(
            $configureAuthor($request->toDto()),
        )->response()->setStatusCode(200);
    }

    public function generateTermoDraft(
        GenerateTenantSerproTermDraftRequest $request,
        GenerateTenantSerproTermDraftAction $generateDraft,
    ): JsonResponse {
        return TenantSerproTermDraftResource::make(
            $generateDraft($request->toDto()),
        )->response()->setStatusCode(201);
    }

    public function downloadTermoDraft(
        DownloadTenantSerproTermDraftRequest $request,
        DownloadTenantSerproTermDraftAction $downloadDraft,
    ): Response {
        $xml = $downloadDraft(
            $request->environment(),
            $request->actor()->id,
        );

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="termo-autorizacao-draft.xml"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function uploadTermo(
        UploadTenantSerproTermRequest $request,
        UploadTenantSerproTermAction $uploadTerm,
    ): JsonResponse {
        return TenantSerproAuthorizationResource::make(
            $uploadTerm($request->toDto()),
        )->response()->setStatusCode(201);
    }

    public function signTermoManagedCertificate(
        SignTenantSerproTermRequest $request,
        SignTenantSerproTermAction $signTerm,
    ): JsonResponse {
        return TenantSerproAuthorizationResource::make(
            $signTerm($request->environment(), $request->actor()->id),
        )->additional([
            'message' => 'Assinatura com certificado enfileirada.',
        ])->response()->setStatusCode(202);
    }

    public function refreshToken(
        RefreshTenantSerproTokenRequest $request,
        RefreshTenantSerproTokenAction $refreshToken,
    ): JsonResponse {
        return TenantSerproAuthorizationResource::make(
            $refreshToken($request->environment(), $request->actor()->id),
        )->response()->setStatusCode(200);
    }

    public function listProxyPowers(
        ListTenantProxyPowersRequest $request,
        ListTenantProxyPowersAction $listProxyPowers,
    ): JsonResponse {
        return (new TaxProxyPowerCollection(
            $listProxyPowers($request->toDto()),
        ))->response();
    }

    public function importProxyPower(
        RejectManualTenantProxyPowerRequest $request,
        RejectManualTenantProxyPowerAction $rejectManualPower,
    ): never {
        $rejectManualPower();
    }

    public function syncProxyPowers(
        SyncTenantProxyPowersRequest $request,
        SyncTenantProxyPowersAction $syncProxyPowers,
    ): JsonResponse {
        return TenantProxyPowerSyncResource::make(
            $syncProxyPowers($request->toDto()),
        )->response();
    }

    public function eligibility(
        EvaluateTenantSerproEligibilityRequest $request,
        EvaluateTenantSerproEligibilityAction $evaluateEligibility,
    ): JsonResponse {
        return TenantSerproEligibilityResource::make(
            $evaluateEligibility($request->toDto(), $request->actor()),
        )->response();
    }

    public function platformHealth(
        ViewTenantSerproAuthorizationRequest $request,
        ShowTenantSerproHealthAction $showHealth,
    ): JsonResponse {
        return TenantSerproHealthResource::make(
            $showHealth($request->environment()),
        )->response();
    }
}
