<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenant\ConfigureSerproAuthorAction;
use App\Actions\Tenant\DownloadSerproTermDraftAction;
use App\Actions\Tenant\EvaluateSerproEligibilityAction;
use App\Actions\Tenant\GenerateSerproTermDraftAction;
use App\Actions\Tenant\ListProxyPowersAction;
use App\Actions\Tenant\RefreshSerproTokenAction;
use App\Actions\Tenant\RejectManualProxyPowerAction;
use App\Actions\Tenant\ShowSerproAuthorizationAction;
use App\Actions\Tenant\ShowSerproHealthAction;
use App\Actions\Tenant\SignSerproTermAction;
use App\Actions\Tenant\SyncProxyPowersAction;
use App\Actions\Tenant\UploadSerproTermAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ConfigureSerproAuthorRequest;
use App\Http\Requests\Tenant\DownloadSerproTermDraftRequest;
use App\Http\Requests\Tenant\EvaluateSerproEligibilityRequest;
use App\Http\Requests\Tenant\GenerateSerproTermDraftRequest;
use App\Http\Requests\Tenant\ListProxyPowersRequest;
use App\Http\Requests\Tenant\RefreshSerproTokenRequest;
use App\Http\Requests\Tenant\RejectManualProxyPowerRequest;
use App\Http\Requests\Tenant\SignSerproTermRequest;
use App\Http\Requests\Tenant\SyncProxyPowersRequest;
use App\Http\Requests\Tenant\UploadSerproTermRequest;
use App\Http\Requests\Tenant\ViewSerproAuthorizationRequest;
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
        ViewSerproAuthorizationRequest $request,
        ShowSerproAuthorizationAction $showAuthorization,
    ): JsonResponse {
        return TenantSerproAuthorizationOverviewResource::make(
            $showAuthorization($request->environment()),
        )->response();
    }

    public function configureAuthor(
        ConfigureSerproAuthorRequest $request,
        ConfigureSerproAuthorAction $configureAuthor,
    ): JsonResponse {
        return TenantSerproAuthorizationResource::make(
            $configureAuthor($request->toDto()),
        )->response()->setStatusCode(200);
    }

    public function generateTermoDraft(
        GenerateSerproTermDraftRequest $request,
        GenerateSerproTermDraftAction $generateDraft,
    ): JsonResponse {
        return TenantSerproTermDraftResource::make(
            $generateDraft($request->toDto()),
        )->response()->setStatusCode(201);
    }

    public function downloadTermoDraft(
        DownloadSerproTermDraftRequest $request,
        DownloadSerproTermDraftAction $downloadDraft,
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
        UploadSerproTermRequest $request,
        UploadSerproTermAction $uploadTerm,
    ): JsonResponse {
        return TenantSerproAuthorizationResource::make(
            $uploadTerm($request->toDto()),
        )->response()->setStatusCode(201);
    }

    public function signTermoManagedCertificate(
        SignSerproTermRequest $request,
        SignSerproTermAction $signTerm,
    ): JsonResponse {
        return TenantSerproAuthorizationResource::make(
            $signTerm($request->environment(), $request->actor()->id),
        )->additional([
            'message' => 'Assinatura com certificado enfileirada.',
        ])->response()->setStatusCode(202);
    }

    public function refreshToken(
        RefreshSerproTokenRequest $request,
        RefreshSerproTokenAction $refreshToken,
    ): JsonResponse {
        return TenantSerproAuthorizationResource::make(
            $refreshToken($request->environment(), $request->actor()->id),
        )->response()->setStatusCode(200);
    }

    public function listProxyPowers(
        ListProxyPowersRequest $request,
        ListProxyPowersAction $listProxyPowers,
    ): JsonResponse {
        return (new TaxProxyPowerCollection(
            $listProxyPowers($request->toDto()),
        ))->response();
    }

    public function importProxyPower(
        RejectManualProxyPowerRequest $request,
        RejectManualProxyPowerAction $rejectManualPower,
    ): never {
        $rejectManualPower();
    }

    public function syncProxyPowers(
        SyncProxyPowersRequest $request,
        SyncProxyPowersAction $syncProxyPowers,
    ): JsonResponse {
        return TenantProxyPowerSyncResource::make(
            $syncProxyPowers($request->toDto()),
        )->response();
    }

    public function eligibility(
        EvaluateSerproEligibilityRequest $request,
        EvaluateSerproEligibilityAction $evaluateEligibility,
    ): JsonResponse {
        return TenantSerproEligibilityResource::make(
            $evaluateEligibility($request->toDto(), $request->actor()),
        )->response();
    }

    public function platformHealth(
        ViewSerproAuthorizationRequest $request,
        ShowSerproHealthAction $showHealth,
    ): JsonResponse {
        return TenantSerproHealthResource::make(
            $showHealth($request->environment()),
        )->response();
    }
}
