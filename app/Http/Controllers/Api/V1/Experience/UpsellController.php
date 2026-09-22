<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Experience;

use App\Domain\Reservations\Models\Reservation;
use App\Domain\Upsells\Models\UpsellOrder;
use App\Domain\Upsells\Models\UpsellProduct;
use App\Domain\Upsells\Services\UpsellService;
use App\Http\Controllers\Controller;
use App\Http\Resources\UpsellOrderResource;
use App\Http\Resources\UpsellProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Extras: the menu, and what guests ordered from it.
 *
 * The menu for a particular stay is filtered by lead time, so it never offers
 * something that cannot be delivered in the time remaining. A menu that
 * promises what it cannot provide is worse than a shorter one — it converts a
 * small extra into a complaint and a refund.
 */
class UpsellController extends Controller
{
    public function __construct(private readonly UpsellService $upsells) {}

    // ------------------------------------------------------------------
    // The menu
    // ------------------------------------------------------------------

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', UpsellProduct::class);

        $query = UpsellProduct::query();

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        if ($request->filled('property_id')) {
            $query->forProperty($request->string('property_id')->toString());
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->string('kind')->toString());
        }

        return UpsellProductResource::collection(
            $query->orderBy('position')->orderBy('name')->paginate($this->perPage()),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', UpsellProduct::class);

        $data = $request->validate($this->productRules());

        $product = new UpsellProduct;
        $product->fill($data);
        $product->organization_id = $this->organization()->getKey();
        $product->currency ??= $this->organization()->base_currency;
        $product->save();

        return (new UpsellProductResource($product))->response()->setStatusCode(201);
    }

    public function show(UpsellProduct $product): UpsellProductResource
    {
        $this->authorize('view', $product);

        return new UpsellProductResource($product);
    }

    public function update(Request $request, UpsellProduct $product): UpsellProductResource
    {
        $this->authorize('update', $product);

        $product->fill($request->validate($this->productRules($product)))->save();

        return new UpsellProductResource($product->fresh());
    }

    /**
     * Withdraw an extra from sale.
     *
     * Deactivated, not deleted: orders placed under it point at it, and the
     * foreign key enforces the same thing at the database.
     */
    public function destroy(UpsellProduct $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $product->forceFill(['is_active' => false])->save();

        return response()->json([
            'message' => 'The extra is no longer offered. Orders already placed are unchanged.',
            'data' => new UpsellProductResource($product->fresh()),
        ]);
    }

    /**
     * What this particular stay could order, and what it would cost.
     */
    public function availableFor(Reservation $reservation): JsonResponse
    {
        $this->authorize('view', $reservation);

        return response()->json([
            'data' => $this->upsells->availableFor($reservation),
        ]);
    }

    // ------------------------------------------------------------------
    // Orders
    // ------------------------------------------------------------------

    public function orders(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', UpsellOrder::class);

        $query = UpsellOrder::query()->with(['product', 'reservation']);

        if ($request->filled('reservation_id')) {
            $query->where('reservation_id', $request->string('reservation_id')->toString());
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        // The two operational queues: what needs a decision, and what has been
        // agreed and not yet delivered.
        if ($request->boolean('awaiting_decision')) {
            $query->awaitingDecision();
        }

        if ($request->boolean('outstanding')) {
            $query->outstanding();
        }

        if ($request->filled('service_date')) {
            $query->where('service_date', $request->date('service_date')->toDateString());
        }

        return UpsellOrderResource::collection(
            $query->orderBy('service_date')->paginate($this->perPage()),
        );
    }

    public function order(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorize('create', UpsellOrder::class);

        $data = $request->validate([
            'upsell_product_id' => ['required', 'string', 'size:26'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'service_date' => ['sometimes', 'nullable', 'date'],
            'guest_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $product = UpsellProduct::query()->findOrFail($data['upsell_product_id']);

        $order = $this->upsells->order(
            $reservation,
            $product,
            (int) ($data['quantity'] ?? 1),
            $data,
        );

        return (new UpsellOrderResource($order->load('product')))
            ->response()
            ->setStatusCode(201);
    }

    public function approve(UpsellOrder $order): UpsellOrderResource
    {
        $this->authorize('decide', $order);

        return new UpsellOrderResource($this->upsells->approve($order)->load('product'));
    }

    public function decline(Request $request, UpsellOrder $order): UpsellOrderResource
    {
        $this->authorize('decide', $order);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new UpsellOrderResource($this->upsells->decline($order, $data['reason'])->load('product'));
    }

    public function fulfil(UpsellOrder $order): UpsellOrderResource
    {
        $this->authorize('decide', $order);

        return new UpsellOrderResource($this->upsells->fulfil($order)->load('product'));
    }

    public function cancelOrder(Request $request, UpsellOrder $order): UpsellOrderResource
    {
        $this->authorize('decide', $order);

        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return new UpsellOrderResource(
            $this->upsells->cancel($order, $data['reason'] ?? null)->load('product'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function productRules(?UpsellProduct $product = null): array
    {
        $creating = $product === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'code' => [
                $creating ? 'required' : 'sometimes', 'string', 'max:48', 'alpha_dash',
                Rule::unique('upsell_products', 'code')
                    ->where('organization_id', $this->organization()->getKey())
                    ->ignore($product?->getKey()),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'kind' => ['sometimes', 'string', 'max:32'],

            // Minor units, like every other amount in this API.
            'price' => [$creating ? 'required' : 'sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'charge_basis' => ['sometimes', Rule::in([
                UpsellProduct::PER_STAY,
                UpsellProduct::PER_NIGHT,
                UpsellProduct::PER_GUEST,
                UpsellProduct::PER_UNIT,
            ])],

            'is_taxable' => ['sometimes', 'boolean'],
            'property_ids' => ['sometimes', 'nullable', 'array'],
            'property_ids.*' => ['string', 'size:26'],

            // The two fields that keep a menu honest.
            'lead_time_hours' => ['sometimes', 'integer', 'min:0', 'max:8760'],
            'daily_capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'max_quantity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'requires_approval' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
