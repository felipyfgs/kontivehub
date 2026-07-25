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
        $this->assertStringStartsWith('https://app.kontivehub.com.br/reset-password?', $actionUrl);
        $this->assertStringContainsString('token=token-test', $actionUrl);
        $this->assertStringContainsString('email=pessoa%40example.test', $actionUrl);
        $this->assertStringContainsString('KontiveHub', $content);
        $this->assertStringNotContainsString('Fiscal Hub', $content);
        $this->assertStringNotContainsString('inovaicontabil.com.br', $actionUrl);
    }
}
