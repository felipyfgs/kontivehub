<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenant\ActivateTenantCertificateAction;
use App\Actions\Tenant\GetTenantCertificateAction;
use App\Actions\Tenant\GetTenantConsentStatusAction;
use App\Actions\Tenant\GrantTenantTechnicalConsentAction;
use App\Actions\Tenant\ListTenantMonitorSchedulesAction;
use App\Actions\Tenant\RefreshTenantIntegrationAction;
use App\Actions\Tenant\RemoveTenantCertificateAction;
use App\Actions\Tenant\RevokeTenantTechnicalConsentAction;
use App\Actions\Tenant\ShowTenantOnboardingStatusAction;
use App\Actions\Tenant\ShowTenantSettingsAction;
use App\Actions\Tenant\UpdateTenantInstitutionalProfileAction;
use App\Actions\Tenant\UpdateTenantMonitorScheduleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\GrantTenantTechnicalConsentRequest;
use App\Http\Requests\Tenant\RefreshTenantIntegrationRequest;
use App\Http\Requests\Tenant\RemoveTenantCertificateRequest;
use App\Http\Requests\Tenant\RevokeTenantTechnicalConsentRequest;
use App\Http\Requests\Tenant\UpdateTenantInstitutionalProfileRequest;
use App\Http\Requests\Tenant\UpdateTenantMonitorScheduleRequest;
use App\Http\Requests\Tenant\UploadTenantCertificateRequest;
use App\Http\Requests\Tenant\ViewTenantSettingsRequest;
use App\Http\Resources\TenantCertificateActivationResource;
use App\Http\Resources\TenantCertificateRemovalResource;
use App\Http\Resources\TenantCertificateStatusResource;
use App\Http\Resources\TenantConsentStatusResource;
use App\Http\Resources\TenantInstitutionalProfileUpdateResource;
use App\Http\Resources\TenantIntegrationRefreshResource;
use App\Http\Resources\TenantMonitorScheduleResource;
use App\Http\Resources\TenantOnboardingStatusResource;
use App\Http\Resources\TenantSettingsResource;
use App\Http\Resources\TenantTechnicalConsentResource;
use Illuminate\Http\JsonResponse;

/**
 * Superfície tenant-scoped de /settings.
 *
 * O tenant é derivado exclusivamente do contexto autenticado. Material PFX
 * nunca é devolvido ao cliente.
 */
final class TenantSettingsController extends Controller
{
    public function show(
        ViewTenantSettingsRequest $request,
        ShowTenantSettingsAction $showSettings,
    ): JsonResponse {
        return TenantSettingsResource::make($showSettings())->response();
    }

    public function updateProfile(
        UpdateTenantInstitutionalProfileRequest $request,
        UpdateTenantInstitutionalProfileAction $updateProfile,
    ): JsonResponse {
        return TenantInstitutionalProfileUpdateResource::make(
            $updateProfile($request->toDto()),
        )->response();
    }

    public function showConsent(
        ViewTenantSettingsRequest $request,
        GetTenantConsentStatusAction $getConsentStatus,
    ): JsonResponse {
        return TenantConsentStatusResource::make($getConsentStatus())->response();
    }

    public function grantConsent(
        GrantTenantTechnicalConsentRequest $request,
        GrantTenantTechnicalConsentAction $grantConsent,
    ): JsonResponse {
        return TenantTechnicalConsentResource::make(
            $grantConsent($request->toDto()),
        )->response()->setStatusCode(201);
    }

    public function revokeConsent(
        RevokeTenantTechnicalConsentRequest $request,
        RevokeTenantTechnicalConsentAction $revokeConsent,
    ): JsonResponse {
        return TenantTechnicalConsentResource::make(
            $revokeConsent($request->actor()->id),
        )->response();
    }

    public function showCertificate(
        ViewTenantSettingsRequest $request,
        GetTenantCertificateAction $getCertificate,
    ): JsonResponse {
        return TenantCertificateStatusResource::make(
            $getCertificate(),
        )->response();
    }

    public function storeCertificate(
        UploadTenantCertificateRequest $request,
        ActivateTenantCertificateAction $activateCertificate,
    ): JsonResponse {
        return TenantCertificateActivationResource::make(
            $activateCertificate($request->toDto()),
        )->response()->setStatusCode(202);
    }

    public function replaceCertificate(
        UploadTenantCertificateRequest $request,
        ActivateTenantCertificateAction $activateCertificate,
    ): JsonResponse {
        return TenantCertificateActivationResource::make(
            $activateCertificate($request->toDto(), replace: true),
        )->response()->setStatusCode(202);
    }

    public function removeCertificate(
        RemoveTenantCertificateRequest $request,
        RemoveTenantCertificateAction $removeCertificate,
    ): JsonResponse {
        return TenantCertificateRemovalResource::make(
            $removeCertificate($request->actor()->id),
        )->response();
    }

    public function refreshIntegration(
        RefreshTenantIntegrationRequest $request,
        RefreshTenantIntegrationAction $refreshIntegration,
    ): JsonResponse {
        return TenantIntegrationRefreshResource::make(
            $refreshIntegration($request->actor()->id),
        )->response();
    }

    public function listMonitorSchedules(
        ViewTenantSettingsRequest $request,
        ListTenantMonitorSchedulesAction $listSchedules,
    ): JsonResponse {
        return TenantMonitorScheduleResource::collection(
            $listSchedules(),
        )->response();
    }

    public function updateMonitorSchedule(
        UpdateTenantMonitorScheduleRequest $request,
        string $monitorKey,
        UpdateTenantMonitorScheduleAction $updateSchedule,
    ): JsonResponse {
        return TenantMonitorScheduleResource::make(
            $updateSchedule($monitorKey, $request->toDto()),
        )->response();
    }

    public function onboardingStatus(
        ViewTenantSettingsRequest $request,
        ShowTenantOnboardingStatusAction $showOnboardingStatus,
    ): JsonResponse {
        return TenantOnboardingStatusResource::make(
            $showOnboardingStatus(),
        )->response();
    }
}
