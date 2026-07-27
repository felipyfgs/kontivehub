<?php

namespace App\Services\Certificates;

use App\Domain\Cnpj;
use App\Enums\TenantFiscalIdentityStatus;
use App\Models\TenantFiscalIdentity;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Identidade fiscal do escritório — tenant_id sempre da sessão.
 * Ambiente (produção/homologação) vive no cursor, não em cópias do certificado.
 */
final class TenantFiscalIdentityService
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
    ) {}

    /**
     * Normaliza e valida CNPJ completo (14) e raiz (8) como texto uppercase.
     *
     * @return array{cnpj: string, root_cnpj: string}
     */
    public function normalizeCnpj(string $raw): array
    {
        $cnpj = Cnpj::parse($raw);

        return [
            'cnpj' => $cnpj->value(),
            'root_cnpj' => $cnpj->root(),
        ];
    }

    public function activeForCurrentTenant(): ?TenantFiscalIdentity
    {
        $tenantId = $this->currentTenant->tenant()->id;

        return TenantFiscalIdentity::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantFiscalIdentityStatus::Active)
            ->orderBy('id')
            ->first();
    }

    public function upsertActive(string $cnpjRaw, ?string $legalName = null): TenantFiscalIdentity
    {
        $tenantId = $this->currentTenant->tenant()->id;
        $normalized = $this->normalizeCnpj($cnpjRaw);

        return DB::transaction(function () use ($tenantId, $normalized, $legalName): TenantFiscalIdentity {
            $existing = TenantFiscalIdentity::query()
                ->where('tenant_id', $tenantId)
                ->where('cnpj', $normalized['cnpj'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status !== TenantFiscalIdentityStatus::Active) {
                    $existing->status = TenantFiscalIdentityStatus::Active;
                    $existing->activated_at = now();
                    $existing->deactivated_at = null;
                }
                if ($legalName !== null) {
                    $existing->legal_name = $legalName;
                }
                $existing->save();

                return $existing;
            }

            // MVP: uma identidade ativa por tenant — desativa as demais da mesma raiz em conflito.
            $otherActive = TenantFiscalIdentity::query()
                ->where('tenant_id', $tenantId)
                ->where('status', TenantFiscalIdentityStatus::Active)
                ->where('root_cnpj', $normalized['root_cnpj'])
                ->where('cnpj', '!=', $normalized['cnpj'])
                ->lockForUpdate()
                ->first();

            if ($otherActive !== null) {
                throw new RuntimeException(
                    'Já existe identidade fiscal ativa com outra raiz ou CNPJ. Desative a atual antes de cadastrar outra.'
                );
            }

            $anyActive = TenantFiscalIdentity::query()
                ->where('tenant_id', $tenantId)
                ->where('status', TenantFiscalIdentityStatus::Active)
                ->lockForUpdate()
                ->first();

            if ($anyActive !== null && $anyActive->cnpj !== $normalized['cnpj']) {
                throw new RuntimeException(
                    'O MVP permite uma identidade fiscal ativa por escritório. Desative a atual antes de cadastrar outra.'
                );
            }

            return TenantFiscalIdentity::query()->create([
                'tenant_id' => $tenantId,
                'cnpj' => $normalized['cnpj'],
                'root_cnpj' => $normalized['root_cnpj'],
                'status' => TenantFiscalIdentityStatus::Active,
                'legal_name' => $legalName,
                'activated_at' => now(),
            ]);
        });
    }

    public function deactivate(TenantFiscalIdentity $identity): TenantFiscalIdentity
    {
        $tenantId = $this->currentTenant->tenant()->id;
        if ($identity->tenant_id !== $tenantId) {
            abort(404);
        }

        $identity->status = TenantFiscalIdentityStatus::Inactive;
        $identity->deactivated_at = now();
        $identity->save();

        return $identity;
    }

    /**
     * Valida string de 14/8 caracteres sem cast numérico (alfanumérico ok).
     */
    public function assertTextCnpj(string $value, int $expectedLength): string
    {
        $normalized = Cnpj::normalize($value);
        if (strlen($normalized) !== $expectedLength) {
            throw new InvalidArgumentException(
                "CNPJ deve ter {$expectedLength} caracteres após normalização (texto, não numérico)."
            );
        }
        if ($expectedLength === 14) {
            return Cnpj::parse($normalized)->value();
        }

        return $normalized;
    }
}
