<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * "Is this address really yours?"
 *
 * Queued, because registration should not sit waiting on an SMTP handshake
 * and should certainly not fail because the mail host is slow.
 */
class VerifyEmailAddress extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your email address for Restrum')
            ->greeting('Nearly there.')
            ->line('Restrum holds a buyer\'s money until their instrument arrives, so we need an address that reaches you if something goes wrong with a sale.')
            ->action('Confirm this address', $this->verificationUrl($notifiable))
            ->line('The link stops working in '.config('auth.verification.expire', 60).' minutes. Ask for a new one from the banner at the top of the site.')
            ->line('If you did not sign up to Restrum, nothing has happened to your address and you can ignore this.');
    }

    /**
     * A signed link, so the address in it cannot be swapped for someone
     * else's on the way through.
     *
     * The hash is of the email rather than the id: changing the address
     * after the link is sent invalidates the link, which is the point.
     */
    protected function verificationUrl(object $notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }
}
