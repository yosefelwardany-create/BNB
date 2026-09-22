<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\Messaging;

use App\Domain\Integrations\Contracts\MessageTransportInterface;
use App\Domain\Integrations\DataObjects\DeliveryResult;
use App\Domain\Integrations\DataObjects\OutboundMessage;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message as MailMessage;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Delivery by email, through whatever mailer the application is configured
 * with.
 *
 * `isLive()` is derived from that configuration rather than hard-coded. The
 * `log` and `array` mailers write the message somewhere and return success, so
 * treating them as live would report a guest had been emailed when nothing
 * left the building — which is exactly the lie this interface exists to
 * prevent.
 */
class EmailTransport implements MessageTransportInterface
{
    /** Mailers that accept a message and deliver it nowhere. */
    private const NON_DELIVERING = ['log', 'array', 'null'];

    public function __construct(private readonly Mailer $mailer) {}

    public function key(): string
    {
        return 'email';
    }

    public function displayName(): string
    {
        return 'Email';
    }

    public function isLive(): bool
    {
        return ! in_array($this->mailerName(), self::NON_DELIVERING, true);
    }

    public function simulationReason(): ?string
    {
        if ($this->isLive()) {
            return null;
        }

        return sprintf(
            'The mailer is set to "%s", which records messages instead of sending them. '
            .'Set MAIL_MAILER to a real transport to deliver email to guests.',
            $this->mailerName(),
        );
    }

    public function canDeliver(OutboundMessage $message): bool
    {
        return filter_var($message->toEmail, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        if (! $this->canDeliver($message)) {
            return DeliveryResult::permanentFailure(
                'no_email_address',
                'The recipient has no email address on file.',
            );
        }

        // Generated so the message can be correlated with a bounce later; a
        // real ESP will usually replace it, in which case its own id wins.
        $messageId = sprintf('%s@habitat', Str::lower((string) Str::ulid()));

        try {
            $this->mailer->html(
                $message->bodyHtml ?? nl2br(e($message->body)),
                function (MailMessage $mail) use ($message, $messageId): void {
                    $mail->to($message->toEmail, $message->toName)
                        ->subject($message->subject ?? 'A message about your stay');

                    if ($message->replyTo !== null) {
                        $mail->replyTo($message->replyTo, $message->fromName);
                    }

                    $mail->text($message->body);

                    $mail->getHeaders()->addTextHeader('Message-ID', sprintf('<%s>', $messageId));

                    foreach ($message->attachments as $attachment) {
                        if (isset($attachment['path'])) {
                            $mail->attach($attachment['path'], ['as' => $attachment['name']]);
                        }
                    }
                },
            );
        } catch (TransportExceptionInterface $exception) {
            // The SMTP layer distinguishes a refused recipient from a
            // connection it could not make; only the latter is worth retrying.
            return DeliveryResult::transientFailure(
                'transport_error',
                $exception->getMessage(),
            );
        } catch (\Throwable $exception) {
            return DeliveryResult::permanentFailure(
                'send_failed',
                $exception->getMessage(),
            );
        }

        return $this->isLive()
            ? DeliveryResult::delivered($messageId, ['mailer' => $this->mailerName()])
            : DeliveryResult::recordedLocally($messageId, [
                'mailer' => $this->mailerName(),
                'note' => 'Written to the mail log; not delivered.',
            ]);
    }

    private function mailerName(): string
    {
        return (string) config('mail.default', 'log');
    }
}
