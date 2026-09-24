<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Experience;

use App\Domain\Documents\Services\DocumentBuilder;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\Payments\Models\Payment;
use App\Domain\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use Illuminate\Http\JsonResponse;

/**
 * Producing the documents people actually file.
 *
 * Each of these renders a PDF, stores it on the private disk and records it, then
 * returns the document so a client can download it through the normal documents
 * endpoint — which already handles the authorisation for reading one.
 *
 * Authorised against the *subject*, not the document: whoever may read a
 * statement may have a copy of it, and whoever may not should not be able to
 * bring one into existence.
 */
class GeneratedDocumentController extends Controller
{
    public function __construct(private readonly DocumentBuilder $documents) {}

    public function ownerStatement(OwnerStatement $statement): JsonResponse
    {
        $this->authorize('view', $statement);

        $document = $this->documents->ownerStatement($statement);

        return response()->json([
            'message' => $statement->status === OwnerStatement::STATUS_DRAFT
                // Said plainly: a draft's figures can still change, and the PDF
                // itself carries the same warning on its face.
                ? 'A draft statement was produced. It is marked as a draft and will be replaced when regenerated.'
                : 'The statement document is ready.',
            'data' => (new DocumentResource($document))->resolve(),
        ], 201);
    }

    public function invoice(Reservation $reservation): JsonResponse
    {
        $this->authorize('view', $reservation);

        $document = $this->documents->invoice($reservation);

        return response()->json([
            'data' => (new DocumentResource($document))->resolve(),
        ], 201);
    }

    public function receipt(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        // Refused rather than produced empty: a receipt for money that was never
        // taken is the one document nobody should be able to generate, and an
        // authorisation that has not been captured is exactly that.
        abort_if(
            $payment->captured_at === null,
            422,
            'This payment has not been captured, so there is nothing to receipt.',
        );

        $document = $this->documents->receipt($payment);

        return response()->json([
            'data' => (new DocumentResource($document))->resolve(),
            'meta' => [
                // The flag travels to the caller as well as onto the page, so an
                // interface can warn before somebody emails it to an accountant.
                'is_simulated' => (bool) $payment->is_simulated,
                'is_collected_by_us' => (bool) $payment->is_collected_by_us,
            ],
        ], 201);
    }
}
