<?php

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Tests\TestCase;

class ResetPasswordNotificationTest extends TestCase
{
    public function test_reset_message_uses_kontivehub_identity_and_canonical_frontend(): void
    {
        $user = new User([
            'name' => 'Pessoa Teste',
            'email' => 'pessoa@example.test',
        ]);

        $message = (new ResetPasswordNotification('token-test'))->toMail($user);
        $actionUrl = (string) $message->actionUrl;
        $content = implode(' ', [
            (string) $message->subject,
            ...$message->introLines,
            ...$message->outroLines,
        ]);

        $this->assertSame('Redefinição de senha — KontiveHub', $message->subject);
        $this->assertStringStartsWith('https://app.kontivehub.com.br/reset-password#', $actionUrl);
        $this->assertStringNotContainsString('?', $actionUrl);
        $this->assertStringContainsString('token=token-test', $actionUrl);
        $this->assertStringContainsString('email=pessoa%40example.test', $actionUrl);
        $this->assertStringContainsString('KontiveHub', $content);
        $this->assertStringNotContainsString('Fiscal Hub', $content);
        $this->assertStringNotContainsString('inovaicontabil.com.br', $actionUrl);
    }

    public function test_reset_message_encodes_fragment_values_with_rfc3986_encoding(): void
    {
        $user = new User([
            'name' => 'Pessoa Teste',
            'email' => 'pessoa+teste@example.test',
        ]);

        $actionUrl = (string) (new ResetPasswordNotification('token +/&='))->toMail($user)->actionUrl;

        $this->assertSame(
            'https://app.kontivehub.com.br/reset-password#token=token%20%2B%2F%26%3D&email=pessoa%2Bteste%40example.test',
            $actionUrl,
        );
    }
}
