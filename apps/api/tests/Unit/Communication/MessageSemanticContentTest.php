<?php

namespace Tests\Unit\Communication;

use App\DTO\Communication\MessageSemanticContent;
use App\Enums\Communication\MessageKind;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MessageSemanticContentTest extends TestCase
{
    #[DataProvider('invalidStructuredContent')]
    public function test_rejects_structured_content_that_cannot_satisfy_the_public_contract(array $content): void
    {
        $this->expectException(InvalidArgumentException::class);

        MessageSemanticContent::assertShape($content, MessageKind::Interactive);
    }

    public static function invalidStructuredContent(): array
    {
        return [
            'poll without name' => [['poll' => ['options' => ['A']]]],
            'contact without vcard' => [['contacts' => [['display_name' => 'Contato']]]],
            'rich card without title' => [['rich_card' => ['category' => 'PRODUCT']]],
            'poll vote without hashes' => [['poll_votes' => [['option_names' => ['A']]]]],
            'poll vote with invalid hash' => [['poll_votes' => [[
                'option_names' => ['A'],
                'option_hashes' => ['invalid'],
            ]]]],
        ];
    }
}
