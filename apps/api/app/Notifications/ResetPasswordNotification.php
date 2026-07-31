<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Redefinição de senha — KontiveHub')
            ->greeting('Olá!')
            ->line('Você recebeu este e-mail porque solicitou a redefinição de senha da sua conta KontiveHub.')
            ->action('Redefinir senha', $this->resetUrl($notifiable))
            ->line('Se você não solicitou a redefinição, nenhuma ação é necessária.');
    }

    protected function resetUrl($notifiable): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/reset-password#'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
