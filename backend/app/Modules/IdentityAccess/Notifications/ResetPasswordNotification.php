<?php

namespace App\Modules\IdentityAccess\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Lien de réinitialisation vers le frontend (FRONTEND_URL/reset-password?token=…&email=…).
 *
 * En file d'attente : le temps de réponse de /auth/forgot-password ne révèle pas si le compte existe.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    protected function resetUrl($notifiable): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    protected function buildMailMessage($url): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject(__('Réinitialisation de votre mot de passe'))
            ->line(__('Vous recevez cet email car une réinitialisation de mot de passe a été demandée pour votre compte.'))
            ->action(__('Choisir un nouveau mot de passe'), $url)
            ->line(__('Ce lien expire dans :count minutes et ne peut être utilisé qu\'une fois.', ['count' => $minutes]))
            ->line(__('Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email : votre mot de passe reste inchangé.'));
    }
}
