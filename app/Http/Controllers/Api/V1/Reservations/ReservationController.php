<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reservations;

use App\Domain\Guests\Models\Guest;
use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Unit;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Services\AccessControl;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly AccessControl $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Reservation::class);

        $restricted = $this->access->restrictedPropertyIds($this->currentUser());

        $query = Reservation::query()
            ->with(['property:id,name,internal_name,timezone,currency', 'guest:id,display_name,email,phone', 'unit:id,name,code'])
            ->when($restricted !== null, fn ($q) => $q->whereIn('property_id', $restricted));

        // --- Filters ----------------------------------------------------
        if ($status = $request->string('status')->toString()) {
            $query->whereIn('status', explode(',', $status));
        }

        foreach (['property_id', 'listing_id', 'unit_id', 'guest_id', 'source'] as $filter) {
            if ($value = $request->string($filter)->toString()) {
                $query->whereIn($filter, explode(',', $value));
            }
        }

        // A date range means "stays that touch this window", which is what an
        // operator means when they ask for next week's bookings.
        if ($request->filled('from') && $request->filled('to')) {
            $query->overlapping(
                $request->date('from')->toDateString(),
                $request->date('to')->toDateString(),
            );
        }

        if ($request->filled('arriving_on')) {
            $query->arrivingOn($request->date('arriving_on')->toDateString());
        }

        if ($request->filled('departing_on')) {
            $query->departingOn($request->date('departing_on')->toDateString());
        }

        if ($request->filled('in_house_on')) {
            $query->inHouseOn($request->date('in_house_on')->toDateString());
        }

        if ($request->boolean('unpaid_only')) {
            $query->where('balance_due', '>', 0);
        }

        if ($search = $request->string('search')->toString()) {
            $like = '%'.str_replace('%', '\%', $search).'%';

            $query->where(function ($q) use ($like): void {
                $q->where('confirmation_code', 'ilike', $like)
                    ->orWhere('external_confirmation_code', 'ilike', $like)
                    ->orWhereHas('guest', fn ($g) => $g->where('display_name', 'ilike', $like)
                        ->orWhere('email', 'ilike', $like));
            });
        }

        $sort = $request->string('sort', 'check_in_date')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';

        if (in_array($sort, ['check_in_date', 'check_out_date', 'created_at', 'grand_total', 'balance_due'], true)) {
            $query->orderBy($sort, $direction);
        }

        return ReservationResource::collection($query->paginate($this->perPage()));
    }

    public function store(StoreReservationRequest $request): JsonResponse
    {
        $this->authorize('create', Reservation::class);

        $listing = Listing::query()->with(['property', 'unitType', 'unit'])
            ->findOrFail($request->input('listing_id'));

        $this->authorize('view', $listing->property);

        $reservation = $this->reservations->create(
            $this->buildRequest($request, $listing)
        );

        return (new ReservationResource($reservation->load(['property', 'guest', 'stayNights', 'charges'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Reservation $reservation): ReservationResource
    {
        $this->authorize('view', $reservation);

        return new ReservationResource($reservation->load([
            'property', 'listing', 'unit', 'guest', 'stayNights', 'charges',
            'additionalGuests', 'statusChanges.user', 'cancellationPolicy',
        ]));
    }

    /**
     * Price a stay without creating anything.
     *
     * The breakdown returned here is produced by the same engine that prices a
     * real booking, so what an agent quotes is what the guest is charged.
     */
    public function quote(Request $request): JsonResponse
    {
        $this->authorize('reservations.view');

        $data = $request->validate([
            'listing_id' => ['required', 'string', 'exists:listings,id'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'infants' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'pets' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'promotion_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'channel' => ['sometimes', 'string', 'max:48'],
        ]);

        $listing = Listing::query()->with(['property', 'unit', 'unitType'])
            ->findOrFail($data['listing_id']);

        $this->authorize('view', $listing->property);

        $quote = $this->reservations->quote(new ReservationRequest(
            listing: $listing,
            checkIn: CarbonImmutable::parse($data['check_in']),
            checkOut: CarbonImmutable::parse($data['check_out']),
            adults: $data['adults'] ?? 1,
            children: $data['children'] ?? 0,
            infants: $data['infants'] ?? 0,
            pets: $data['pets'] ?? 0,
            source: $data['channel'] ?? 'direct',
            promotionCode: $data['promotion_code'] ?? null,
        ));

        return response()->json(['data' => $quote->toArray()]);
    }

    /**
     * Change dates, unit or guest counts.
     */
    public function update(Request $request, Reservation $reservation): ReservationResource
    {
        $this->authorize('update', $reservation);

        $data = $request->validate([
            'check_in' => ['sometimes', 'date'],
            'check_out' => ['sometimes', 'date', 'after:check_in'],
            'unit_id' => ['sometimes', 'nullable', 'string', Rule::exists('units', 'id')
                ->where('property_id', $reservation->property_id)],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'infants' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'pets' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $changes = [];

        if (isset($data['check_in'])) {
            $changes['check_in'] = CarbonImmutable::parse($data['check_in']);
        }

        if (isset($data['check_out'])) {
            $changes['check_out'] = CarbonImmutable::parse($data['check_out']);
        }

        foreach (['unit_id', 'adults', 'children', 'infants', 'pets'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }

        $this->reservations->modify($reservation, $changes, $data['reason'] ?? null);

        return new ReservationResource($reservation->fresh(['property', 'guest', 'stayNights', 'charges']));
    }

    /**
     * Update fields that do not affect inventory or price.
     */
    public function updateDetails(Request $request, Reservation $reservation): ReservationResource
    {
        $this->authorize('update', $reservation);

        $data = $request->validate([
            'guest_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'check_in_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'check_out_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'guest_id' => ['sometimes', 'nullable', 'string', 'exists:guests,id'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:60'],
        ]);

        $tags = $data['tags'] ?? null;
        unset($data['tags']);

        $reservation->fill($data)->save();

        if ($tags !== null) {
            $reservation->syncTags($tags);
        }

        return new ReservationResource($reservation->fresh(['property', 'guest']));
    }

    public function transition(Request $request, Reservation $reservation, string $action): ReservationResource
    {
        $target = match ($action) {
            'confirm' => ReservationStatus::Confirmed,
            'check-in' => ReservationStatus::CheckedIn,
            'check-out' => ReservationStatus::CheckedOut,
            'no-show' => ReservationStatus::NoShow,
            default => abort(404),
        };

        $this->authorize(
            in_array($action, ['check-in', 'check-out'], true) ? 'checkIn' : 'update',
            $reservation,
        );

        $this->reservations->transitionTo(
            $reservation,
            $target,
            $request->string('reason')->toString() ?: null,
        );

        return new ReservationResource($reservation->fresh(['property', 'guest']));
    }

    /**
     * What the guest would be refunded if the booking were cancelled now.
     */
    public function refundPreview(Reservation $reservation): JsonResponse
    {
        $this->authorize('view', $reservation);

        $refund = $this->reservations->calculateCancellationRefund($reservation);

        return response()->json([
            'refund' => $refund['refund']->jsonSerialize(),
            'retained' => $refund['retained']->jsonSerialize(),
            'refund_percent' => $refund['refund_percent'],
            'explanation' => $refund['explanation'],
            'breakdown' => $refund['breakdown'],
        ]);
    }

    public function cancel(Request $request, Reservation $reservation): ReservationResource
    {
        $this->authorize('cancel', $reservation);

        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cancelled_by' => ['sometimes', Rule::in(['guest', 'host', 'channel', 'system'])],
            'waive_fees' => ['sometimes', 'boolean'],
        ]);

        // Waiving the cancellation fee is a financial decision, so it carries
        // its own permission rather than riding on the cancel permission.
        if (($data['waive_fees'] ?? false) === true) {
            $this->authorize('payments.refund');
        }

        $this->reservations->cancel(
            $reservation,
            $data['reason'] ?? null,
            $data['cancelled_by'] ?? 'host',
            $data['waive_fees'] ?? false,
        );

        return new ReservationResource($reservation->fresh(['property', 'guest']));
    }

    public function reinstate(Request $request, Reservation $reservation): ReservationResource
    {
        $this->authorize('reinstate', $reservation);

        $this->reservations->reinstate($reservation, $request->string('reason')->toString() ?: null);

        return new ReservationResource($reservation->fresh(['property', 'guest']));
    }

    public function addCharge(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorize('update', $reservation);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['fee', 'discount', 'damage', 'adjustment', 'upsell'])],
            'label' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'integer', 'min:0'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_taxable' => ['sometimes', 'boolean'],
            'is_refundable' => ['sometimes', 'boolean'],
        ]);

        $charge = $this->reservations->addCharge(
            $reservation,
            $data['kind'],
            $data['label'],
            Money::of($data['amount'], $reservation->currency),
            [
                'description' => $data['description'] ?? null,
                'is_taxable' => $data['is_taxable'] ?? false,
                'is_refundable' => $data['is_refundable'] ?? true,
            ],
        );

        return response()->json([
            'data' => [
                'id' => $charge->getKey(),
                'kind' => $charge->kind,
                'label' => $charge->label,
                'amount' => $charge->amount()->jsonSerialize(),
            ],
            'reservation' => (new ReservationResource($reservation->fresh()))->resolve(),
        ], 201);
    }

    private function buildRequest(StoreReservationRequest $request, Listing $listing): ReservationRequest
    {
        $guest = $request->filled('guest_id')
            ? Guest::query()->findOrFail($request->input('guest_id'))
            : null;

        $unit = $request->filled('unit_id')
            ? Unit::query()->findOrFail($request->input('unit_id'))
            : null;

        $overrideRestrictions = $request->boolean('override_restrictions')
            && $this->currentUser()->can('reservations.override_availability');

        return new ReservationRequest(
            listing: $listing,
            checkIn: CarbonImmutable::parse($request->input('check_in')),
            checkOut: CarbonImmutable::parse($request->input('check_out')),
            adults: (int) $request->input('adults', 1),
            children: (int) $request->input('children', 0),
            infants: (int) $request->input('infants', 0),
            pets: (int) $request->input('pets', 0),
            status: ReservationStatus::from($request->input('status', 'confirmed')),
            source: $request->input('source', 'direct'),
            guest: $guest,
            guestAttributes: $guest === null ? $request->input('guest') : null,
            unit: $unit,
            promotionCode: $request->input('promotion_code'),
            guestNotes: $request->input('guest_notes'),
            internalNotes: $request->input('internal_notes'),
            overrideRestrictions: $overrideRestrictions,
        );
    }
}
