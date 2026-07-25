<?php

namespace App\Support\Work;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Concorrência otimista via lock_version → HTTP 409 sanitizado.
 */
final class OptimisticLock
{
    /**
     * @throws HttpResponseException 409 se versão divergente
     */
    public static function assert(Model $model, int $expectedVersion, string $resource = 'resource'): void
    {
        $current = (int) $model->getAttribute('lock_version');
        if ($current !== $expectedVersion) {
            throw new HttpResponseException(response()->json([
                'message' => 'Conflito de versão: o registro foi alterado por outro usuário.',
                'error' => 'OPTIMISTIC_LOCK_CONFLICT',
                'resource' => $resource,
                'current_lock_version' => $current,
            ], 409));
        }
    }

    /**
     * Atualiza atributos e incrementa lock_version atomicamente.
     * Retorna false se a versão esperada não corresponder (0 linhas afetadas).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function update(Model $model, int $expectedVersion, array $attributes): bool
    {
        $table = $model->getTable();
        $key = $model->getKeyName();

        $payload = array_merge($attributes, [
            'lock_version' => $expectedVersion + 1,
            'updated_at' => now(),
        ]);

        $affected = DB::table($table)
            ->where($key, $model->getKey())
            ->where('lock_version', $expectedVersion)
            ->update($payload);

        if ($affected === 0) {
            $model->refresh();

            return false;
        }

        $model->refresh();

        return true;
    }

    /**
     * Como update(), mas aborta 409 se falhar.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function updateOrConflict(Model $model, int $expectedVersion, array $attributes, string $resource = 'resource'): void
    {
        if (! self::update($model, $expectedVersion, $attributes)) {
            self::assert($model, $expectedVersion, $resource);
            // assert sempre lança se divergente; se refresh trouxe mesma versão, force 409 genérico
            throw new HttpResponseException(response()->json([
                'message' => 'Conflito de versão: o registro foi alterado por outro usuário.',
                'error' => 'OPTIMISTIC_LOCK_CONFLICT',
                'resource' => $resource,
                'current_lock_version' => (int) $model->getAttribute('lock_version'),
            ], 409));
        }
    }
}
