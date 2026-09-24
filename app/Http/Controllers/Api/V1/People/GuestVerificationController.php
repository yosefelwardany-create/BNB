<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\People;

use App\Domain\Guests\Models\Guest;
use App\Domain\Guests\Services\IdentityVerification;
use App\Http\Controllers\Controller;
use App\Http\Resources\GuestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verifying a guest's identity.
 *
 * Every response carries whether a real verification service was involved. An
 * interface that showed a green tick for a locally-checked document would be
 * telling an operator something nobody established.
 */
class GuestVerificationController extends Controller
{
    public function __construct(private readonly IdentityVerification $verification) {}

    /**
     * Run a check.
     */
    public function check(Request $request, Guest $guest): JsonResponse
    {
        $this->authorize('update', $guest);

        $data = $request->validate([
            'document_type' => ['sometimes', 'string', 'max:32'],
            // Encrypted at rest by the model's casts; never returned in full.
            'document_number' => ['sometimes', 'string', 'max:64'],
            'document_expiry' => ['sometimes', 'nullable', 'date'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
        ]);

        $outcome = $this->verification->check($guest, $data);
        $result = $outcome['result'];

        return response()->json([
            'data' => (new GuestResource($outcome['guest']))->resolve(),
            'meta' => [
                'outcome' => $result->outcome,
                'reasons' => $result->reasons,
                'is_simulated' => $outcome['is_simulated'],
                'simulation_reason' => $outcome['simulation_reason'],
                // Said plainly, because "manual review" reads like a failure and
                // is not one: it is the best a local check can honestly return.
                'requires_a_person' => $result->outcome === 'manual_review',
            ],
        ]);
    }

    /**
     * A person decides.
     */
    public function decide(Request $request, Guest $guest): JsonResponse
    {
        $this->authorize('update', $guest);

        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $updated = $this->verification->decide(
            $guest,
            (bool) $data['approved'],
            $this->currentUser(),
            $data['reason'],
        );

        return response()->json([
            'message' => $data['approved']
                ? 'The guest has been marked as verified.'
                : 'The document has been rejected.',
            'data' => (new GuestResource($updated))->resolve(),
        ]);
    }
}
