<?php

namespace App\Modules\Monitoring\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mail d'alerte envoyé à l'astreinte (P9-02) : sujet court, corps en texte brut. */
class MonitoringAlertMail extends Mailable
{
    public function __construct(public readonly string $subjectLine, public readonly string $body) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<pre style="font-family: sans-serif; white-space: pre-wrap">'.e($this->body).'</pre>');
    }
}
