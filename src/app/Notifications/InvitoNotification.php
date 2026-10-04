<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email sent to a newly created person, with the link to choose a password.
 * It contains no data about children, only the recipient's own name.
 */
class InvitoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The invitation reuses the password reset page: choosing a password
        // for the first time is the same operation as resetting it.
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $giorni = intdiv((int) config('auth.passwords.inviti.expire'), 60 * 24);

        return (new MailMessage)
            ->subject('Sei stato invitato su Piedinauti')
            ->greeting("Ciao {$notifiable->nome},")
            ->line('È stato creato un account per te su Piedinauti, il servizio per organizzare il piedibus.')
            ->line('Per iniziare scegli la tua password.')
            ->action('Imposta la password', $url)
            ->line("Il link è valido per {$giorni} giorni. Se non te lo aspettavi puoi ignorare questa email.")
            ->salutation('Il team di Piedinauti');
    }
}
