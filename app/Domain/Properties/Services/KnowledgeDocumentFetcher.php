<?php

declare(strict_types=1);

namespace App\Domain\Properties\Services;

use App\Domain\Agents\Exceptions\BotEndpointRefusedException;
use App\Domain\Agents\Support\BotEndpoint;
use App\Domain\Properties\Models\PropertyDocument;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads a property's knowledge document so its agent can answer from it.
 *
 * The link was only ever a link: a person could open it and the agent could not,
 * so an agent sat answering from ten public facts while the answer was in a
 * document nobody had given it.
 *
 * ## Four things this is careful about
 *
 * **A Google Docs link is not a document.** The share URL serves an application,
 * not text. Google publishes an export endpoint that does serve text, and
 * rewriting to it is the difference between storing a house manual and storing
 * a page of JavaScript. It only works where the document is shared — which is
 * the common failure, so it is reported in those words rather than as "403".
 *
 * **The address is operator-supplied and this server fetches it.** That is a
 * request-forgery primitive handed to a customer, on a multi-tenant platform.
 * It goes through the same guard as a bot endpoint: TLS, and nothing on this
 * machine's own network.
 *
 * **Size is capped here, not by the column.** A column limit truncates
 * mid-sentence leaving no record, and the agent then answers from half a
 * paragraph believing it has the whole thing. The cap is applied knowingly and
 * the row says it happened.
 *
 * **A failure is written down.** "Never fetched" and "fetched, and Google said
 * no" are different states, and only the second is something an operator can
 * go and fix.
 */
class KnowledgeDocumentFetcher
{
    /**
     * How much of a document is worth carrying.
     *
     * Everything stored here is read into a prompt, where it costs money and
     * latency on every question. A house manual is a few thousand words; a
     * hundred thousand characters is somebody's entire operations wiki, and
     * taking the first slice of it is both cheaper and more honest than
     * refusing outright — provided the row says it was cut.
     */
    private const MAX_BYTES = 120_000;

    public function refresh(PropertyDocument $document): PropertyDocument
    {
        $document->checked_at = CarbonImmutable::now();

        try {
            // Re-checked at fetch time, not only when it was saved: a name that
            // was public when somebody typed it can point somewhere else now.
            $endpoint = BotEndpoint::parse($this->fetchableUrl($document->url));
        } catch (BotEndpointRefusedException $e) {
            return $this->settle($document, PropertyDocument::STATUS_REFUSED, $e->getMessage());
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['Accept' => 'text/plain, text/html;q=0.9, */*;q=0.1'])
                ->timeout((int) config('pms.agents.knowledge.timeout', 20))
                // Google answers the export endpoint with a redirect more often
                // than not, and a document behind one is not a document we
                // failed to fetch.
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($endpoint->url);
        } catch (ConnectionException $e) {
            return $this->settle(
                $document,
                PropertyDocument::STATUS_UNREACHABLE,
                sprintf('%s could not be reached: %s', $endpoint->host, $e->getMessage()),
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            /*
             * Almost always a document that is not shared.
             *
             * Said in those words rather than as a status code, because the fix
             * is three clicks in Google Docs and "403 Forbidden" tells nobody
             * which three.
             */
            return $this->settle(
                $document,
                PropertyDocument::STATUS_FORBIDDEN,
                'The document is not readable without signing in. Open it, press Share, and set '
                .'"Anyone with the link" to Viewer — then refresh this.',
            );
        }

        if ($response->failed()) {
            return $this->settle(
                $document,
                PropertyDocument::STATUS_UNREACHABLE,
                sprintf('%s answered %d.', $endpoint->host, $response->status()),
            );
        }

        $text = $this->plainText($response->body(), (string) $response->header('Content-Type'));

        if (trim($text) === '') {
            return $this->settle(
                $document,
                PropertyDocument::STATUS_EMPTY,
                'The document was read and there was nothing in it.',
            );
        }

        $truncated = mb_strlen($text, '8bit') > self::MAX_BYTES;

        if ($truncated) {
            $text = mb_strcut($text, 0, self::MAX_BYTES);
        }

        $document->forceFill([
            'content' => $text,
            'content_bytes' => mb_strlen($text, '8bit'),
            'content_hash' => hash('sha256', $text),
            'was_truncated' => $truncated,
            'status' => PropertyDocument::STATUS_OK,
            'failure' => null,
            'fetched_at' => CarbonImmutable::now(),
        ])->save();

        return $document;
    }

    /**
     * The address that actually serves text.
     *
     * A Google Docs share link serves an application. Its export endpoint serves
     * the document, and rewriting to it is the difference between storing a
     * house manual and storing a page of JavaScript.
     *
     * Anything else is fetched as given: a published page, a raw file, somebody's
     * own wiki.
     */
    public function fetchableUrl(string $url): string
    {
        $url = trim($url);

        if (preg_match('#^https?://docs\.google\.com/document/d/([a-zA-Z0-9_-]+)#', $url, $matches) === 1) {
            return sprintf('https://docs.google.com/document/d/%s/export?format=txt', $matches[1]);
        }

        // A published-to-web Google Doc already serves readable HTML; asking it
        // for text as well does no harm and often helps.
        if (str_contains($url, 'docs.google.com/document/') && str_contains($url, '/pub')) {
            return $url;
        }

        return $url;
    }

    /**
     * Readable text out of whatever came back.
     */
    private function plainText(string $body, string $contentType): string
    {
        $isHtml = str_contains(mb_strtolower($contentType), 'html')
            || preg_match('/<\s*(html|body|div|p)\b/i', mb_substr($body, 0, 2000)) === 1;

        if ($isHtml) {
            // Scripts and styles first: their contents are not prose, and
            // strip_tags would otherwise leave a page of CSS in the middle of a
            // house manual.
            $body = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', ' ', $body) ?? $body;
            $body = preg_replace('#<(br|/p|/div|/li|/h[1-6])\s*/?>#i', "\n", $body) ?? $body;
            $body = strip_tags($body);
            $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Collapse the blank space a converted document is mostly made of,
        // without losing the paragraph breaks that carry its structure.
        $body = preg_replace("/[ \t]+/", ' ', $body) ?? $body;
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;

        return trim($body);
    }

    private function settle(PropertyDocument $document, string $status, string $failure): PropertyDocument
    {
        $document->forceFill([
            'status' => $status,
            'failure' => mb_substr($failure, 0, 1000),
            // Content is deliberately left alone. A document that was readable
            // yesterday and is not today should keep answering from yesterday's
            // copy with yesterday's date on it, rather than going silent.
        ])->save();

        return $document;
    }
}
