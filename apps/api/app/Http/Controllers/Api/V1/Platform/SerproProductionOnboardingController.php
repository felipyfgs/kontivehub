<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Serpro\StoreProductionOnboardingRequest;
use App\Http\Resources\SerproProductionOnboardingResource;
use App\Models\SerproProductionOnboarding;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Serpro\SerproProductionOnboardingService;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class SerproProductionOnboardingController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly TenantAuthorization $tenantAuthorization,
        private readonly SerproProductionOnboardingService $onboarding,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $office = $this->currentOffice->resolve($request->user());
        if ($office === null) {
            return $this->officeRequired();
        }

        $state = $this->onboarding->latestForOffice($office);

        return response()->json([
            'data' => $this->envelope($request, $office->id, $state),
        ]);
    }

    public function store(StoreProductionOnboardingRequest $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $office = $this->currentOffice->resolve($actor);
        if ($office === null) {
            return $this->officeRequired();
        }

        if (! $this->tenantAuthorization->allows($actor, TenantPermission::CredentialsManage)) {
            return response()->json([
                'message' => 'Você não possui permissão para gerenciar credenciais deste tenant.',
                'code' => 'tenant_permission_denied',
            ], 403);
        }

        try {
            $state = $this->onboarding->activate($office, $actor, $request->toDto());
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'desabilitada') ? 403 : 422;

            return response()->json([
                'message' => $this->sanitize($e->getMessage()),
                'code' => $status === 403 ? 'feature_disabled' : 'serpro_production_onboarding_failed',
            ], $status);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Falha ao ativar SERPRO em produção.',
                'code' => 'serpro_production_onboarding_failed',
            ], 500);
        }

        return response()->json([
            'data' => $this->envelope($request, $office->id, $state),
        ], 201);
    }

    private function officeRequired(): JsonResponse
    {
        return response()->json([
            'message' => 'Selecione um escritório ativo para ativar SERPRO em produção.',
            'code' => CurrentOffice::CONTEXT_STATUS_REQUIRED,
        ], 409);
    }

    /**
     * @return array{version: string, text: string, text_sha256: string}
     */
    private function publicConsent(): array
    {
        $text = (string) config('serpro.production_onboarding.consent_text', '');

        return [
            'version' => (string) config('serpro.production_onboarding.consent_version', 'serpro-prod-onboarding.v1'),
            'text' => $text,
            'text_sha256' => hash('sha256', $text),
        ];
    }

    private function envelope(Request $request, int $officeId, ?SerproProductionOnboarding $state): array
    {
        return [
            'enabled' => FeatureFlags::isSerproProductionOnboardingEnabled($officeId),
            'office_id' => $officeId,
            'consent' => $this->publicConsent(),
            'onboarding' => $state !== null
                ? (new SerproProductionOnboardingResource($state))->toArray($request)
                : null,
        ];
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/\b[A-Za-z0-9+\/]{40,}={0,2}\b/', '[redacted]', $message) ?? $message;
        $message = preg_replace('/(consumer[_-]?secret|password|senha|pfx|jwt|xml|token)[^,;. ]*/i', '$1=[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }
}
