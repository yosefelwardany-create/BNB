<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\Payments\Enums\PaymentKind;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RefundResource;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Taking money and giving it back.
 *
 * Every endpoint here delegates to the payment service rather than touching
 * the model: the ordering of "check what we allow, ask the processor, record
 * what it said" is the whole safety property, and an HTTP layer that
 * shortcuts it would be a second, weaker implementation of the same rules.
 *
 * Amounts arrive as minor units — cents, pence, fils — and never as decimals.
 * A float is not a currency, and "12.30" reaching a processor as 1229 is a
 * class of bug this API makes impossible to write.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Payment::class);

        $query = Payment::query()->with('reservation');

        if ($request->filled('reservation_id')) {
            $query->where('reservation_id', $request->string('reservation_id')->toString());
        }

        if ($request->filled('guest_id')) {
            $query->where('guest_id', $request->string('guest_id')->toString());
        }

        if ($request->filled('property_id')) {
            $query->where('property_id', $request->string('property_id')->toString());
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->string('kind')->toString());
        }

        // "What did we actually bank" is a different question from "what did
        // the guest pay", and the difference is every channel-collected
        // booking.
        if ($request->boolean('collected_by_us_only')) {
            $query->collectedByUs();
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to'));
        }

        return PaymentResource::collection(
            $query->latest()->paginate($this->perPage()),
        );
    }

    public function show(Payment $payment): PaymentResource
    {
        $this->authorize('view', $payment);

        return new PaymentResource($payment->load(['refunds', 'reservation']));
    }

    /**
     * Hold funds without taking them.
     *
     * The normal shape of this business: authorised at booking, captured on
     * arrival. Nothing posts to the ledger — an authorisation moves no money.
     *
     * Named `hold` rather than `authorize`, which is already the controller's
     * permission check. Two different meanings of the same word, and only one
     * of them is about cards.
     */
    public function hold(Request $request): JsonResponse
    {
        $this->authorize('charge', Payment::class);

        $data = $request->validate($this->chargeRules());

        $payment = $this->payments->authorize(
            $this->amountFrom($data),
            $this->reservationFrom($data),
            $this->attributesFrom($data),
        );

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }

    /**
     * Take money against an existing authorisation.
     *
     * Partial captures are normal — a guest who shortens their stay — so the
     * amount is optional and defaults to whatever is left on the hold.
     */
    public function capture(Request $request, Payment $payment): PaymentResource
    {
        $this->authorize('charge', $payment);

        $data = $request->validate([
            'amount' => ['sometimes', 'integer', 'min:1'],
        ]);

        $amount = isset($data['amount'])
            ? Money::of((int) $data['amount'], $payment->currency)
            : null;

        return new PaymentResource($this->payments->capture($payment, $amount));
    }

    /**
     * Authorise and capture in one step.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('charge', Payment::class);

        $data = $request->validate($this->chargeRules());

        $payment = $this->payments->charge(
            $this->amountFrom($data),
            $this->reservationFrom($data),
            $this->attributesFrom($data),
        );

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }

    /**
     * Record money that moved without us.
     *
     * A channel that collected from the guest itself, a bank transfer somebody
     * reconciled off a statement, cash at the door. Kept distinct from a
     * charge because routing it through a processor would simulate a capture
     * that never happened — and this platform does not pretend.
     */
    public function external(Request $request): JsonResponse
    {
        $this->authorize('charge', Payment::class);

        $data = $request->validate($this->chargeRules() + [
            'fee_amount' => ['sometimes', 'integer', 'min:0'],
            'is_collected_by_us' => ['sometimes', 'boolean'],
        ]);

        $payment = $this->payments->recordExternalPayment(
            $this->amountFrom($data),
            $this->reservationFrom($data),
            $this->attributesFrom($data) + [
                'fee_amount' => $data['fee_amount'] ?? 0,
                // Defaults to false: the whole point of this endpoint is money
                // that somebody else is holding.
                'is_collected_by_us' => (bool) ($data['is_collected_by_us'] ?? false),
            ],
        );

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }

    /**
     * Give money back.
     */
    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('refund', $payment);

        $data = $request->validate([
            'amount' => ['sometimes', 'integer', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:64'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $refund = $this->payments->refund(
            $payment,
            isset($data['amount']) ? Money::of((int) $data['amount'], $payment->currency) : null,
            $data['reason'] ?? null,
            array_filter(['notes' => $data['notes'] ?? null]),
        );

        return (new RefundResource($refund))->response()->setStatusCode(201);
    }

    /**
     * Release an authorisation that will not be taken.
     */
    public function void(Request $request, Payment $payment): PaymentResource
    {
        $this->authorize('void', $payment);

        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return new PaymentResource($this->payments->void($payment, $data['reason'] ?? null));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function amountFrom(array $data): Money
    {
        return Money::of(
            (int) $data['amount'],
            $data['currency'] ?? $this->organization()->base_currency,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reservationFrom(array $data): ?Reservation
    {
        if (! isset($data['reservation_id'])) {
            return null;
        }

        // Tenant-scoped by the global scope, so a reservation id from another
        // organization simply does not exist here.
        return Reservation::query()->findOrFail($data['reservation_id']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributesFrom(array $data): array
    {
        return array_filter([
            'kind' => $data['kind'] ?? null,
            'guest_id' => $data['guest_id'] ?? null,
            'property_id' => $data['property_id'] ?? null,
            'method' => $data['method'] ?? null,
            'provider' => $data['provider'] ?? null,
            'provider_reference' => $data['provider_reference'] ?? null,
            'description' => $data['description'] ?? null,
            'instrument_token' => $data['instrument_token'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function chargeRules(): array
    {
        return [
            // Minor units. Never a decimal: see the class docblock.
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'reservation_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'guest_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'property_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'kind' => ['sometimes', 'string', 'in:'.implode(',', array_column(PaymentKind::cases(), 'value'))],
            'method' => ['sometimes', 'nullable', 'string', 'max:32'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:48'],
            'provider_reference' => ['sometimes', 'nullable', 'string', 'max:191'],
            'instrument_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
