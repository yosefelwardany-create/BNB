<?php

declare(strict_types=1);

namespace App\Domain\Users\Notifications;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Invitation $invitation,
        private readonly string $plainToken,
        private readonly Organization $organization,
    ) {
        $this->onQueue(config('pms.queues.messaging'));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/invitations/'.$this->plainToken;

        return (new MailMessage)
            ->subject(sprintf('You have been invited to %s', $this->organization->name))
            ->greeting($this->invitation->first_name ? 'Hello '.$this->invitation->first_name.',' : 'Hello,')
            ->line(sprintf(
                '%s has invited you to join their team on %s.',
                $this->organization->name,
                config('app.name'),
            ))
            ->action('Accept invitation', $url)
            ->line(sprintf(
                'This invitation expires on %s.',
                $this->invitation->expires_at->toDayDateTimeString(),
            ))
            ->line('If you were not expecting this invitation you can safely ignore this email.');
    }
}
