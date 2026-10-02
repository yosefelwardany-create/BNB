<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Properties\Models\PropertyDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A document a property's agent reads, as the screen needs it.
 *
 * The text itself is not sent. It is up to 120KB, nothing on the screen renders
 * it, and the document is one click away at its own URL where it is current
 * rather than a snapshot. What the screen needs is whether the agent can read
 * it, when it last managed to, and — the field that matters most — whether this
 * document reaches guests.
 *
 * @mixin PropertyDocument
 */
class PropertyDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PropertyDocument $document */
        $document = $this->resource;

        return [
            'id' => $document->getKey(),
            'kind' => $document->kind,
            'label' => $document->label(),
            'url' => $document->url,

            'status' => $document->status,
            'is_usable' => $document->isUsable(),
            'failure' => $document->failure,

            'is_guest_safe' => $document->is_guest_safe,
            'content_bytes' => $document->content_bytes,
            'was_truncated' => $document->was_truncated,

            'fetched_at' => $document->fetched_at?->toIso8601String(),
            'checked_at' => $document->checked_at?->toIso8601String(),

            /*
             * Whether this document contains the property's own secrets.
             *
             * The one check worth running: a house manual with the door code in
             * it, marked guest-safe, hands that code to anybody who writes in —
             * walking around the entitlement gate rather than through it. The
             * platform does not refuse, because an operator may have a reason;
             * it refuses to let them do it without being told.
             */
            'contains_secrets' => $this->secretsIn($document),
        ];
    }

    /**
     * Which of the property's stored secrets appear in this document.
     *
     * By name, never by value: listing the door code in an API response to warn
     * about the door code being readable would be its own joke.
     *
     * @return list<string>
     */
    private function secretsIn(PropertyDocument $document): array
    {
        if (! $document->isUsable()) {
            return [];
        }

        $property = $document->property;

        if ($property === null) {
            return [];
        }

        $found = [];
        $haystack = mb_strtolower((string) $document->content);

        foreach ([
            'door code' => $property->door_code,
            'wifi password' => $property->wifi_password,
            'access notes' => $property->access_notes,
        ] as $label => $secret) {
            // Short values match by accident — a door code of "1" appears in
            // every document ever written — so only something long enough to be
            // distinctive counts.
            if (is_string($secret) && mb_strlen(trim($secret)) >= 4
                && str_contains($haystack, mb_strtolower(trim($secret)))) {
                $found[] = $label;
            }
        }

        return $found;
    }
}
