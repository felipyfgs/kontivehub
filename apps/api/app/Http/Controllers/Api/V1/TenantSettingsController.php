<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tenant\ActivateCertificateAction;
use App\Actions\Tenant\GetCertificateAction;
use App\Actions\Tenant\GetConsentStatusAction;
use App\Actions\Tenant\GrantTechnicalConsentAction;
use App\Actions\Tenant\ListMonitorSchedulesAction;
use App\Actions\Tenant\RefreshIntegrationAction;
use App\Actions\Tenant\RemoveCertificateAction;
use App\Actions\Tenant\RevokeTechnicalConsentAction;
use App\Actions\Tenant\ShowOnboardingStatusAction;
use App\Actions\Tenant\ShowSettingsAction;
use App\Actions\Tenant\UpdateInstitutionalProfileAction;
use App\Actions\Tenant\UpdateMonitorScheduleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\GrantTechnicalConsentRequest;
use App\Http\Requests\Tenant\RefreshIntegrationRequest;
use App\Http\Requests\Tenant\RemoveCertificateRequest;
use App\Http\Requests\Tenant\RevokeTechnicalConsentRequest;
use App\Http\Requests\Tenant\UpdateInstitutionalProfileRequest;
use App\Http\Requests\Tenant\UpdateMonitorScheduleRequest;
use App\Http\Requests\Tenant\UploadCertificateRequest;
use App\Http\Requests\Tenant\ViewSettingsRequest;
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
        ViewSettingsRequest $request,
        ShowSettingsAction $showSettings,
    ): JsonResponse {
        return TenantSettingsResource::make($showSettings())->response();
    }

    public function updateProfile(
        UpdateInstitutionalProfileRequest $request,
        UpdateInstitutionalProfileAction $updateProfile,
    ): JsonResponse {
        return TenantInstitutionalProfileUpdateResource::make(
            $updateProfile($request->toDto()),
        )->response();
    }

    public function showConsent(
        ViewSettingsRequest $request,
        GetConsentStatusAction $getConsentStatus,
    ): JsonResponse {
        return TenantConsentStatusResource::make($getConsentStatus())->response();
    }

    public function grantConsent(
        GrantTechnicalConsentRequest $request,
        GrantTechnicalConsentAction $grantConsent,
    ): JsonResponse {
        return TenantTechnicalConsentResource::make(
            $grantConsent($request->toDto()),
        )->response()->setStatusCode(201);
    }

    public function revokeConsent(
        RevokeTechnicalConsentRequest $request,
        RevokeTechnicalConsentAction $revokeConsent,
    ): JsonResponse {
        return TenantTechnicalConsentResource::make(
            $revokeConsent($request->actor()->id),
        )->response();
    }

    public function showCertificate(
        ViewSettingsRequest $request,
        GetCertificateAction $getCertificate,
    ): JsonResponse {
        return TenantCertificateStatusResource::make(
            $getCertificate(),
        )->response();
    }

    public function storeCertificate(
        UploadCertificateRequest $request,
        ActivateCertificateAction $activateCertificate,
    ): JsonResponse {
        return TenantCertificateActivationResource::make(
            $activateCertificate($request->toDto()),
        )->response()->setStatusCode(202);
    }

    public function replaceCertificate(
        UploadCertificateRequest $request,
        ActivateCertificateAction $activateCertificate,
    ): JsonResponse {
        return TenantCertificateActivationResource::make(
            $activateCertificate($request->toDto(), replace: true),
        )->response()->setStatusCode(202);
    }

    public function removeCertificate(
        RemoveCertificateRequest $request,
        RemoveCertificateAction $removeCertificate,
    ): JsonResponse {
        return TenantCertificateRemovalResource::make(
            $removeCertificate($request->actor()->id),
        )->response();
    }

    public function refreshIntegration(
        RefreshIntegrationRequest $request,
        RefreshIntegrationAction $refreshIntegration,
    ): JsonResponse {
        return TenantIntegrationRefreshResource::make(
            $refreshIntegration($request->actor()->id),
        )->response();
    }

    public function listMonitorSchedules(
        ViewSettingsRequest $request,
        ListMonitorSchedulesAction $listSchedules,
    ): JsonResponse {
        return TenantMonitorScheduleResource::collection(
            $listSchedules(),
        )->response();
    }

    public function updateMonitorSchedule(
        UpdateMonitorScheduleRequest $request,
        string $monitorKey,
        UpdateMonitorScheduleAction $updateSchedule,
    ): JsonResponse {
        return TenantMonitorScheduleResource::make(
            $updateSchedule($monitorKey, $request->toDto()),
        )->response();
    }

    public function onboardingStatus(
        ViewSettingsRequest $request,
        ShowOnboardingStatusAction $showOnboardingStatus,
    ): JsonResponse {
        return TenantOnboardingStatusResource::make(
            $showOnboardingStatus(),
        )->response();
    }
}
