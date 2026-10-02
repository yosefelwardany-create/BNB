<?php

declare(strict_types=1);

namespace App\Domain\Channels\Jobs;

use App\Domain\Channels\Models\ChannelWebhookEvent;
use App\Domain\Channels\Services\ChannelMessageImporter;
use App\Domain\Channels\Services\ReservationImporter;
use App\Domain\Integrations\DataObjects\ChannelMessagePayload;
use App\Domain\Integrations\Providers\Channels\HostexChannelAdapter;
use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Turns one recorded channel event into a booking, a message or a note.
 *
 * Off the request so the channel gets its 202 immediately: a sender treats a
 * slow response as a failure and retries, which would turn one booking into
 * several.
 *
 * Every path settles the event with what happened — including the paths that do
 * nothing. An event that arrives, is understood, and is correctly ignored must
 * say `ignored` rather than sit on `pending`, or the only way to tell "we have
 * not got to it" from "there was nothing to do" is to read this file.
 */
class ProcessChannelWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $eventId)
    {
        $this->onQueue(config('pms.queues.channels', 'channels'));
    }

    public function handle(
        TenantContext $tenancy,
        ReservationImporter $reservations,
        ChannelMessageImporter $messages,
        HostexChannelAdapter $hostex,
    ): void {
        $event = $tenancy->withoutScope(
            fn (): ?ChannelWebhookEvent => ChannelWebhookEvent::query()
                ->withoutGlobalScope('organization')
                ->with('channelAccount')
                ->find($this->eventId),
        );

        if ($event === null || $event->status !== ChannelWebhookEvent::PENDING) {
            // Already settled: this job ran twice, or a sweep got there first.
            // Doing the work again would duplicate a booking.
            return;
        }

        $organization = $tenancy->withoutScope(
            fn (): ?Organization => Organization::query()->find($event->organization_id),
        );

        if ($organization === null) {
            $event->settle(ChannelWebhookEvent::FAILED, 'The organization no longer exists.');

            return;
        }

        $tenancy->runAs($organization, function () use ($event, $reservations, $messages, $hostex): void {
            $account = $event->channelAccount;

            if ($account === null) {
                $event->settle(ChannelWebhookEvent::FAILED, 'The channel connection no longer exists.');

                return;
            }

            match (true) {
                str_contains($event->type, 'reservation') => $this->reservation($event, $reservations, $hostex),
                str_contains($event->type, 'message') => $this->message($event, $messages),
                default => $event->settle(
                    ChannelWebhookEvent::IGNORED,
                    sprintf('Nothing in this platform reacts to "%s" yet.', $event->type),
                ),
            };
        });
    }

    private function reservation(
        ChannelWebhookEvent $event,
        ReservationImporter $reservations,
        HostexChannelAdapter $hostex,
    ): void {
        $payload = $hostex->reservation($event->payload);

        if ($payload === null) {
            /*
             * Understood as a reservation event and unreadable as a
             * reservation. Failed rather than ignored, and the payload is on
             * the row: this is a booking we have been told about and do not
             * have, which is the one state somebody must find out about.
             */
            $event->settle(
                ChannelWebhookEvent::FAILED,
                'The reservation in this event could not be read. The payload is on this row.',
            );

            return;
        }

        $outcome = $reservations->importOne($event->channelAccount, $payload);

        $event->settle(
            $outcome['result'] === 'failed' ? ChannelWebhookEvent::FAILED : ChannelWebhookEvent::PROCESSED,
            sprintf('%s %s', $outcome['result'], $payload->externalReservationId),
        );
    }

    private function message(ChannelWebhookEvent $event, ChannelMessageImporter $messages): void
    {
        $data = $event->payload;

        $body = $this->text($data, ['message', 'body', 'content', 'text']);
        $thread = $this->text($data, ['conversation_id', 'thread_id']);

        if ($body === null || $thread === null) {
            $event->settle(
                ChannelWebhookEvent::FAILED,
                'This message event carried no body or no conversation to attach it to.',
            );

            return;
        }

        /*
         * Which way the message went.
         *
         * Read from the sender rather than assumed, because a thread carries
         * the host's side too. Importing one of ours as the guest's would have
         * the agent answering itself; importing it as sent by us would invent a
         * sending history. Anything unrecognised is treated as the guest's,
         * which is the direction that gets a person's attention rather than
         * one that quietly closes a thread.
         */
        $sender = mb_strtolower((string) ($this->text($data, ['sender_role', 'sender_type', 'direction']) ?? 'guest'));
        $fromGuest = ! in_array($sender, ['host', 'outbound', 'owner', 'manager'], true);

        $message = $messages->record(
            $event->channelAccount,
            new ChannelMessagePayload(
                body: $body,
                externalThreadId: $thread,
                externalMessageId: $this->text($data, ['id', 'message_id']),
                externalReservationId: $this->text($data, ['reservation_code', 'reservation_id']),
                senderName: $this->text($data, ['sender_name', 'guest_name']),
                sentAt: $this->when($data, ['created_at', 'sent_at', 'timestamp']),
                // The listing, so a thread with no booking behind it can still
                // be attached to the right property.
                attachments: array_filter([
                    'listing_id' => $this->text($data, ['property_id', 'listing_id']),
                ], static fn (mixed $value): bool => $value !== null),
            ),
            fromGuest: $fromGuest,
        );

        $event->settle(
            $message === null ? ChannelWebhookEvent::IGNORED : ChannelWebhookEvent::PROCESSED,
            $message === null
                ? 'Already recorded, or the listing is not mapped to a property here.'
                : sprintf('Recorded as message %s.', $message->getKey()),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function text(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function when(array $data, array $keys): ?DateTimeImmutable
    {
        $raw = $this->text($data, $keys);

        if ($raw === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A job that gave up still settles its event.
     *
     * Otherwise a payload this platform cannot read sits on `pending` forever,
     * which reads as a queue that has not caught up rather than a thing that
     * needs looking at.
     */
    public function failed(?Throwable $exception): void
    {
        ChannelWebhookEvent::query()
            ->withoutGlobalScope('organization')
            ->whereKey($this->eventId)
            ->where('status', ChannelWebhookEvent::PENDING)
            ->update([
                'status' => ChannelWebhookEvent::FAILED,
                'outcome' => mb_substr($exception?->getMessage() ?? 'The job failed.', 0, 2000),
                'processed_at' => now(),
            ]);
    }
}
