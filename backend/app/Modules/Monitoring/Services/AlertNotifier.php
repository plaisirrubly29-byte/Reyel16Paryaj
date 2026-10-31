<?php

namespace App\Modules\Monitoring\Services;

use App\Modules\Monitoring\Mail\MonitoringAlertMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envoi d'un message à l'astreinte sur chaque canal configuré (Telegram, e-mail). Les canaux sont indépendants :
 * une panne de l'un n'empêche pas l'autre. Aucun secret (jeton du bot) n'est écrit dans les logs.
 */
class AlertNotifier
{
    /** @return array<string, bool|null> par canal : true envoyé, false échec, null non configuré */
    public function send(string $subject, string $body): array
    {
        $results = ['telegram' => $this->telegram($subject, $body), 'mail' => $this->mail($subject, $body)];

        if ($results['telegram'] === null && $results['mail'] === null) {
            Log::warning('monitoring.no_channel', ['subject' => $subject]);
        }

        return $results;
    }

    private function telegram(string $subject, string $body): ?bool
    {
        $token = config('monitoring.telegram.bot_token');
        $chat = config('monitoring.telegram.chat_id');
        if (blank($token) || blank($chat)) {
            return null;
        }

        try {
            $response = Http::timeout(10)->retry(2, 1000, throw: false)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chat,
                    'text' => "{$subject}\n\n{$body}",
                    'disable_web_page_preview' => true,
                ]);
            if ($response->successful()) {
                return true;
            }
            Log::error('monitoring.telegram_failed', ['status' => $response->status()]);
        } catch (Throwable $e) {
            Log::error('monitoring.telegram_failed', ['error' => class_basename($e)]);
        }

        return false;
    }

    private function mail(string $subject, string $body): ?bool
    {
        $to = config('monitoring.mail_to');
        if ($to === []) {
            return null;
        }

        try {
            Mail::to($to)->send(new MonitoringAlertMail($subject, $body));

            return true;
        } catch (Throwable $e) {
            Log::error('monitoring.mail_failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
