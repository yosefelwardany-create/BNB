<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Integrations\DataObjects\OutboundMessage;
use App\Domain\Integrations\Providers\Messaging\EmailTransport;
use App\Domain\Integrations\Providers\Messaging\LocalTransport;
use App\Domain\Integrations\Registries\MessageTransportRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Transports report what actually happened.
 *
 * The behaviour under test is honesty. A transport that accepts a message and
 * delivers it nowhere — the log mailer, the local recorder — must say so, and
 * the product must be able to tell the difference. Every surface that shows a
 * guest message reads that flag, so a transport lying here would make the whole
 * inbox lie.
 */
class MessageTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_email_transport_hands_a_complete_message_to_the_mailer(): void
    {
        $transport = $this->app->make(EmailTransport::class);

        $result = $transport->send(new OutboundMessage(
            body: 'Your keys are in the lockbox.',
            subject: 'Arrival instructions',
            toEmail: 'guest@example.test',
            toName: 'Marta Silva',
            fromName: 'Casa Verde',
            replyTo: 'stay@example.test',
        ));

        // A composition error here — a malformed header, a missing sender —
        // fails every guest message rather than one, so the success path is
        // asserted rather than assumed.
        $this->assertTrue(
            $result->successful,
            sprintf('The transport refused the message: %s', (string) $result->errorMessage),
        );

        $this->assertNotNull($result->externalMessageId);
    }

    public function test_the_email_transport_reports_a_non_delivering_mailer_as_simulated(): void
    {
        $transport = $this->app->make(EmailTransport::class);

        // The test environment uses the array mailer, which accepts everything
        // and delivers nothing.
        $this->assertFalse($transport->isLive());
        $this->assertStringContainsString('array', (string) $transport->simulationReason());

        $result = $transport->send(new OutboundMessage(
            body: 'Hello',
            toEmail: 'guest@example.test',
        ));

        $this->assertTrue($result->successful);
        $this->assertTrue($result->simulated);

        // Sent, not delivered. Nothing confirmed receipt, and claiming
        // otherwise is a lie the inbox would repeat.
        $this->assertSame('sent', $result->messageStatus());
    }

    public function test_the_email_transport_refuses_a_recipient_it_cannot_address(): void
    {
        $transport = $this->app->make(EmailTransport::class);

        $message = new OutboundMessage(body: 'Hello', toPhone: '+351912345678');

        $this->assertFalse($transport->canDeliver($message));

        $result = $transport->send($message);

        $this->assertTrue($result->failed());
        $this->assertSame('no_email_address', $result->errorCode);

        // Permanent: retrying will not conjure an address.
        $this->assertFalse($result->retryable);

        // And nothing was handed to the mailer: the transport refused before
        // composing, rather than sending to an empty address.
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_the_local_transport_keeps_what_it_did_not_send(): void
    {
        $transport = $this->app->make(LocalTransport::class);

        $result = $transport->send(new OutboundMessage(
            body: 'The Wi-Fi password is sunshine-42.',
            subject: 'Your stay',
            toEmail: 'guest@example.test',
            organizationId: null,
            language: 'en',
        ));

        $this->assertTrue($result->successful);
        $this->assertTrue($result->simulated);
        $this->assertFalse($transport->isLive());

        // Not a stub that returns true: the rendered body a guest would have
        // received is readable afterwards.
        $row = DB::table('simulated_message_deliveries')
            ->where('reference', $result->externalMessageId)
            ->first();

        $this->assertNotNull($row);
        $this->assertStringContainsString('sunshine-42', $row->body);
        $this->assertSame('guest@example.test', $row->recipient);
    }

    public function test_the_local_transport_accepts_anything_so_nothing_is_dropped(): void
    {
        $transport = $this->app->make(LocalTransport::class);

        // No address of any kind. The point of the fallback is that a message
        // with nowhere to go is still kept and visibly marked undelivered,
        // rather than vanishing.
        $this->assertTrue($transport->canDeliver(new OutboundMessage(body: 'Orphan')));
    }

    public function test_the_registry_reports_every_transport_honestly(): void
    {
        $status = $this->app->make(MessageTransportRegistry::class)->status();

        $this->assertNotEmpty($status);

        foreach ($status as $transport) {
            // A settings screen must never be able to show a transport as live
            // without the platform being able to say why it is not.
            if (! $transport['live']) {
                $this->assertNotEmpty(
                    $transport['reason'],
                    sprintf('The %s transport is not live but gives no reason.', $transport['key']),
                );
            }
        }
    }
}
