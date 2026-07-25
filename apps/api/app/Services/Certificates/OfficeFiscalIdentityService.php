<?php

namespace App\Services\Certificates;

use App\Domain\Cnpj;
use App\Enums\OfficeFiscalIdentityStatus;
use App\Models\OfficeFiscalIdentity;
use App\Support\CurrentOffice;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Identidade fiscal do escritório — office_id sempre da sessão.
 * Ambiente (produção/homologação) vive no cursor, não em cópias do A1.
 */
final class OfficeFiscalIdentityService
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
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

    public function activeForCurrentOffice(): ?OfficeFiscalIdentity
    {
        $officeId = $this->currentOffice->office()->id;

        return OfficeFiscalIdentity::query()
            ->where('office_id', $officeId)
            ->where('status', OfficeFiscalIdentityStatus::Active)
            ->orderBy('id')
            ->first();
    }

    public function upsertActive(string $cnpjRaw, ?string $legalName = null): OfficeFiscalIdentity
    {
        $officeId = $this->currentOffice->office()->id;
        $normalized = $this->normalizeCnpj($cnpjRaw);

        return DB::transaction(function () use ($officeId, $normalized, $legalName): OfficeFiscalIdentity {
            $existing = OfficeFiscalIdentity::query()
                ->where('office_id', $officeId)
                ->where('cnpj', $normalized['cnpj'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status !== OfficeFiscalIdentityStatus::Active) {
                    $existing->status = OfficeFiscalIdentityStatus::Active;
                    $existing->activated_at = now();
                    $existing->deactivated_at = null;
                }
                if ($legalName !== null) {
                    $existing->legal_name = $legalName;
                }
                $existing->save();

                return $existing;
            }

            // MVP: uma identidade ativa por office — desativa as demais da mesma raiz em conflito.
            $otherActive = OfficeFiscalIdentity::query()
                ->where('office_id', $officeId)
                ->where('status', OfficeFiscalIdentityStatus::Active)
                ->where('root_cnpj', $normalized['root_cnpj'])
                ->where('cnpj', '!=', $normalized['cnpj'])
                ->lockForUpdate()
                ->first();

            if ($otherActive !== null) {
                throw new RuntimeException(
                    'Já existe identidade fiscal ativa com outra raiz ou CNPJ. Desative a atual antes de cadastrar outra.'
                );
            }

            $anyActive = OfficeFiscalIdentity::query()
                ->where('office_id', $officeId)
                ->where('status', OfficeFiscalIdentityStatus::Active)
                ->lockForUpdate()
                ->first();

            if ($anyActive !== null && $anyActive->cnpj !== $normalized['cnpj']) {
                throw new RuntimeException(
                    'O MVP permite uma identidade fiscal ativa por escritório. Desative a atual antes de cadastrar outra.'
                );
            }

            return OfficeFiscalIdentity::query()->create([
                'office_id' => $officeId,
                'cnpj' => $normalized['cnpj'],
                'root_cnpj' => $normalized['root_cnpj'],
                'status' => OfficeFiscalIdentityStatus::Active,
                'legal_name' => $legalName,
                'activated_at' => now(),
            ]);
        });
    }

    public function deactivate(OfficeFiscalIdentity $identity): OfficeFiscalIdentity
    {
        $officeId = $this->currentOffice->office()->id;
        if ($identity->office_id !== $officeId) {
            abort(404);
        }

        $identity->status = OfficeFiscalIdentityStatus::Inactive;
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
