<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Domain\Agents\Services\DeferredAgent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a bot posts the answer to a question Habitat asked it earlier.
 *
 * Unauthenticated, because there is nobody to authenticate. The caller is an
 * agent run on somebody else's infrastructure: it has no Habitat account, no
 * session and no API key, and giving it one would mean handing a third party a
 * credential that reads this tenant's data in order to let it write one sentence.
 *
 * So the URL is the credential, as it is for the guest portal — and this one is
 * narrower than that in every direction. It answers exactly one ask, it works
 * once, it is good for half an hour, it is stored only as a hash, and it grants
 * no read of anything. The worst an attacker with a live token can do is put
 * words in a draft that a person then reads on the Agents screen, labelled with
 * which bot it came from and held for review unless it passes all four gates.
 *
 * It returns the same 404 for a token that was never issued, one already used
 * and one that has expired. Telling those apart would tell somebody guessing
 * which half of the problem they have solved.
 */
class AgentCallbackController extends Controller
{
    public function __construct(private readonly DeferredAgent $agent) {}

    public function store(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            // `reply` is what Habitat documents; the other two are accepted
            // because a bot somebody already runs is likelier to answer in its
            // own shape than to be rewritten for this one.
            'reply' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'text' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'message' => ['sometimes', 'nullable', 'string', 'max:8000'],

            'intent' => ['sometimes', 'nullable', 'string', 'max:60'],

            // Deliberately unbounded here and clamped in the service. A bot that
            // reports 95 meaning 95% should have its answer recorded with a
            // confidence of 1.0, not rejected with a validation error it has no
            // way to read.
            'confidence' => ['sometimes', 'nullable', 'numeric'],

            // A bot saying it could not answer. An outcome worth recording, not
            // a malformed request.
            'error' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $ask = $this->agent->receive($token, $validated);

        if ($ask === null) {
            return response()->json([
                'message' => 'That callback is not open. It may already have been answered, or it may have run out of time.',
            ], 404);
        }

        return response()->json(['data' => [
            'accepted' => $ask->status === $ask::STATUS_ANSWERED,
            'ask_id' => $ask->getKey(),
            'status' => $ask->status,
            // What Habitat made of the answer, so a bot's operator can see why a
            // reply that looked fine is waiting for a person.
            'read_as' => [
                'intent' => $ask->intent,
                'confidence' => $ask->confidence,
            ],
            'would_auto_send' => $ask->would_auto_send,
            'held_because' => $ask->held_because,
            // Stated rather than implied: nothing here has gone to a guest.
            'was_sent' => false,
        ]]);
    }
}
