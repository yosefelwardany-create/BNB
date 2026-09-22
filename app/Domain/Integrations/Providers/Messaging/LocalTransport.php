<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\Messaging;

use App\Domain\Integrations\Contracts\MessageTransportInterface;
use App\Domain\Integrations\DataObjects\DeliveryResult;
use App\Domain\Integrations\DataObjects\OutboundMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A working local transport that records what would have been sent.
 *
 * This is not a stub that returns true. It persists every delivery, which
 * makes it genuinely useful: the demonstration data has a full message
 * history, the automation tests assert on what was actually produced, and a
 * developer can read the rendered body a guest would have received rather than
 * guessing at it from a template.
 *
 * It always reports `isLive() === false`, and every result it returns is
 * marked simulated, so no surface can present it as a delivered message.
 */
class LocalTransport implements MessageTransportInterface
{
    private const TABLE = 'simulated_message_deliveries';

    public function key(): string
    {
        return 'local';
    }

    public function displayName(): string
    {
        return 'Local delivery log (development)';
    }

    public function isLive(): bool
    {
        return false;
    }

    public function simulationReason(): ?string
    {
        return 'Messages are recorded locally and never reach a recipient. '
            .'Configure a real transport before taking bookings.';
    }

    /**
     * Anything can be recorded. That is the point of the fallback: a message
     * that no live transport can address is still kept, visible, and clearly
     * marked as undelivered, rather than silently dropped.
     */
    public function canDeliver(OutboundMessage $message): bool
    {
        return true;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $reference = sprintf('local_%s', Str::lower((string) Str::ulid()));

        DB::table(self::TABLE)->insert([
            'id' => (string) Str::ulid(),
            'reference' => $reference,
            'organization_id' => $message->organizationId,
            'message_id' => $message->messageId,
            'channel' => $message->channel,
            'recipient' => Str::limit($message->recipientDescription(), 250, ''),
            'subject' => $message->subject === null ? null : Str::limit($message->subject, 250, ''),
            'body' => $message->body,
            'body_html' => $message->bodyHtml,
            'language' => $message->language,
            'attachments_count' => count($message->attachments),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DeliveryResult::recordedLocally($reference, [
            'recipient' => $message->recipientDescription(),
        ]);
    }
}
