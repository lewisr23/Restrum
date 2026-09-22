<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What every notification on this site has in common.
 *
 * There are eight of these and they differ only in wording and in where they
 * point, so the parts that must not differ live here: the shape of the
 * stored record, whether an email goes out, and the fact that all of it
 * happens after the database transaction has committed.
 *
 * That last point is the one worth stating plainly. Notifications are sent
 * from inside services that do their work in a transaction, and a queued job
 * runs on a different connection: a worker can otherwise pick up "you sold a
 * guitar" before the row saying so is visible, read an order in its old
 * state, and send something untrue. It is a race that will not reproduce on
 * a developer's machine, where the queue usually runs in the same process.
 *
 * The fix is the queue connection's own after_commit flag rather than a
 * property here, because it is not a fact about notifications: see
 * config/queue.php. Redeclaring $afterCommit on this class is also a fatal
 * error, since Illuminate\Bus\Queueable already defines it and PHP refuses
 * to compose a trait property with a different default.
 */
abstract class MarketplaceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Short machine name, so the client can pick an icon without parsing prose. */
    abstract public function kind(): string;

    /** One line, in the bell. */
    abstract public function title(object $notifiable): string;

    /** A sentence of detail, under the title. */
    abstract public function body(object $notifiable): string;

    /** Where clicking it goes, as a path within the app. */
    abstract public function path(object $notifiable): string;

    /**
     * Whether this one is worth an email as well as a bell.
     *
     * Default yes, because the whole point is reaching someone who is not
     * looking at the site. The ones that override it to false are the ones
     * that would arrive several times an hour during a normal conversation,
     * and an inbox full of those is how a person learns to filter everything
     * this site sends, including the one that says their money moved.
     */
    public function emailsToo(): bool
    {
        return true;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        // An unverified address is one nobody has proved reaches a person.
        // Sending to it is at best pointless and at worst posting somebody
        // else's sale details to a stranger who owns that mailbox.
        if ($this->emailsToo() && $notifiable->hasVerifiedEmail()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind(),
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'path' => $this->path($notifiable),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title($notifiable).' - Restrum')
            ->line($this->body($notifiable))
            ->action('Open it on Restrum', rtrim(config('app.frontend_url'), '/').$this->path($notifiable));
    }
}
