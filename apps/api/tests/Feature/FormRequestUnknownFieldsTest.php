<?php

namespace Tests\Feature;

use App\Http\Requests\Communication\StoreContactRequest;
use Illuminate\Routing\Router;
use Tests\TestCase;

final class FormRequestUnknownFieldsTest extends TestCase
{
    public function test_testing_environment_rejects_unknown_form_request_fields(): void
    {
        app(Router::class)->post(
            '/api/_test/form-request-fields',
            static fn (StoreContactRequest $request) => response()->json($request->validated()),
        );

        $this->postJson('/api/_test/form-request-fields', [
            'name' => 'Contato',
            'phone' => '+5511999999999',
        ])
            ->assertOk()
            ->assertExactJson([
                'name' => 'Contato',
                'phone' => '+5511999999999',
            ]);

        $this->postJson('/api/_test/form-request-fields', [
            'name' => 'Contato',
            'phone' => '+5511999999999',
            'receives_automtic' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receives_automtic');
    }
}
