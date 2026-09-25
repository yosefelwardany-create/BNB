<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that records requests and answers from a script.
 *
 * The Anthropic SDK takes its transporter as an injected PSR-18 client, which
 * means the request it builds can be inspected without going near the network.
 * That matters more here than in most integrations: the things most likely to be
 * wrong about this provider are invisible from the outside of a live call — is
 * the cache breakpoint on the block that holds the facts, does the schema really
 * constrain the intent to the categories the agent gates on, is `maxTokens`
 * spelled the way the SDK wants. A mocked provider object would assert none of
 * it.
 */
class RecordingTransporter implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    private array $responses = [];

    /**
     * Queue a response body. Consumed in order; the last one repeats, so a test
     * that only cares about the request does not have to count calls.
     *
     * @param  array<string, mixed>  $body
     */
    public function willAnswer(array $body): self
    {
        $this->responses[] = $body;

        return $this;
    }

    /**
     * The decoded body of the nth request, newest last.
     *
     * @return array<string, mixed>
     */
    public function bodyOf(int $index = 0): array
    {
        $request = $this->requests[$index] ?? null;

        if ($request === null) {
            return [];
        }

        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $body = $this->responses === []
            ? $this->textResponse('No response was queued.')
            : ($this->responses[min(count($this->requests), count($this->responses)) - 1]);

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode($body),
        );
    }

    /**
     * A well-formed message response carrying one text block.
     *
     * @return array<string, mixed>
     */
    public function textResponse(string $text): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 80],
        ];
    }
}
