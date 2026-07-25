<?php

namespace Tests\Unit\MeiAutomation;

use App\Services\MeiAutomation\MeiAutomationInputPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MeiAutomationInputPolicyTest extends TestCase
{
    public function test_pgmei_das_preserves_valid_due_date(): void
    {
        $input = (new MeiAutomationInputPolicy)->sanitize('pgmei.gerardaspdf', [
            'cnpj' => '11.222.333/0001-81',
            'competencies' => ['2026-07'],
            'due_date' => '2026-07-20',
        ]);

        self::assertSame('2026-07-20', $input['due_date']);
    }

    public function test_normalizes_only_fields_allowed_for_operation(): void
    {
        $input = (new MeiAutomationInputPolicy)->sanitize('pgmei.dividaativa', [
            'cnpj' => '11.222.333/0001-81',
            'calendar_year' => '2026',
        ]);

        self::assertSame([
            'cnpj' => '11222333000181',
            'calendar_year' => 2026,
        ], $input);
    }

    public function test_rejects_unknown_field_without_echoing_its_value(): void
    {
        try {
            (new MeiAutomationInputPolicy)->sanitize('pgmei.dividaativa', [
                'cnpj' => '11222333000181',
                'password' => 'segredo-que-nao-pode-cruzar',
            ]);
            self::fail('Campo fora da allowlist deveria falhar.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('password', $error->getMessage());
            self::assertStringNotContainsString('segredo-que-nao-pode-cruzar', $error->getMessage());
        }
    }

    public function test_rejects_numeric_cnpj_to_preserve_alphanumeric_contract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MeiAutomationInputPolicy)->sanitize('ccmei.dadosccmei', ['cnpj' => 11222333000181]);
    }
}
