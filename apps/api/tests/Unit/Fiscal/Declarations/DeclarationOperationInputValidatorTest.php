<?php

namespace Tests\Unit\Fiscal\Declarations;

use App\Services\Fiscal\Declarations\DeclarationOperationInputValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DeclarationOperationInputValidatorTest extends TestCase
{
    public function test_it_normalizes_curated_read_parameters(): void
    {
        $validator = app(DeclarationOperationInputValidator::class);

        $this->assertSame(
            ['calendar_year' => 2026],
            $validator->validate('pgdasd.consdeclaracao', ['calendar_year' => '2026']),
        );
        $this->assertSame(
            ['period_key' => '2026-06', 'assessment_id' => 31],
            $validator->validate('mit.consapuracao', [
                'period_key' => '2026-06',
                'assessment_id' => '31',
            ]),
        );
    }

    public function test_pgdas_declarations_requires_exactly_one_period_selector(): void
    {
        $validator = app(DeclarationOperationInputValidator::class);

        foreach ([[], ['calendar_year' => 2026, 'period_key' => '2026-06']] as $invalid) {
            try {
                $validator->validate('pgdasd.consdeclaracao', $invalid);
                $this->fail('A seleção de período deveria ser rejeitada.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('params', $e->errors());
            }
        }
    }

    public function test_it_rejects_unknown_and_nested_technical_fields(): void
    {
        $validator = app(DeclarationOperationInputValidator::class);

        try {
            $validator->validate('mit.listaapuracoes', ['operation_key' => 'mit.listaapuracoes']);
            $this->fail('operation_key deveria ser rejeitada.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('params.operation_key', $e->errors());
        }

        try {
            $validator->validate('mit.encapuracao', [
                'calendar_year' => 2026,
                'business_payload' => ['autorPedidoDados' => ['numero' => '123']],
            ]);
            $this->fail('Identidade técnica aninhada deveria ser rejeitada.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('params.autorPedidoDados', $e->errors());
        }
    }

    public function test_it_rejects_invalid_month_and_base64(): void
    {
        $validator = app(DeclarationOperationInputValidator::class);

        foreach ([
            ['period_key' => '2026-13', 'signed_xml_base64' => base64_encode('<xml/>')],
            ['period_key' => '2026-06', 'signed_xml_base64' => '***'],
        ] as $invalid) {
            $this->expectValidationFailure(fn () => $validator->validate('dctfweb.transdeclaracao', $invalid));
        }
    }

    private function expectValidationFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('O payload deveria ser rejeitado.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }
}
