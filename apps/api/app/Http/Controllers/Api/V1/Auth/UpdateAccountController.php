<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Atualiza somente a identidade global do próprio usuário autenticado. */
class UpdateAccountController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->merge([
            'name' => trim((string) $request->input('name', '')),
            'email' => mb_strtolower(trim((string) $request->input('email', ''))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        $changed = array_keys($user->getDirty());
        $user->save();

        $this->audit->record(
            action: 'account.profile_updated',
            subject: $user,
            context: ['fields' => $changed],
            userId: $user->id,
        );

        return response()
            ->json([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ])
            ->header('Cache-Control', 'no-store');
    }
}
