<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Revenue;

use App\Domain\Pricing\Models\FeeRule;
use App\Http\Controllers\Controller;
use App\Http\Resources\FeeRuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Charges added on top of the nightly rate.
 *
 * `charge_basis` is the field that matters: the same amount charged per stay,
 * per night, or per guest per night produces wildly different totals, and it
 * is the single most common mis-configuration in this part of a PMS.
 *
 * Three independent booleans that a single "mandatory" flag would conflate:
 * whether tax is charged on the fee, whether it comes back when a guest
 * cancels, and whether the guest may decline it. A cleaning fee is taxable,
 * usually refundable and never optional; a mid-stay clean is all three the
 * other way around.
 */
class FeeRuleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FeeRule::class);

        $query = FeeRule::query();

        if ($request->filled('property_id')) {
            $property = $request->string('property_id')->toString();

            $query->where(fn ($q) => $q->where('property_id', $property)->orWhereNull('property_id'));
        }

        if ($request->filled('listing_id')) {
            $listing = $request->string('listing_id')->toString();

            $query->where(fn ($q) => $q->where('listing_id', $listing)->orWhereNull('listing_id'));
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->string('kind')->toString());
        }

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        return FeeRuleResource::collection(
            $query->orderBy('position')->orderBy('name')->paginate($this->perPage()),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', FeeRule::class);

        $data = $request->validate($this->rules());

        $fee = new FeeRule;
        $fee->fill($data);
        $fee->organization_id = $this->organization()->getKey();
        $fee->currency ??= $this->organization()->base_currency;
        $fee->save();

        return (new FeeRuleResource($fee))->response()->setStatusCode(201);
    }

    public function show(FeeRule $feeRule): FeeRuleResource
    {
        $this->authorize('view', $feeRule);

        return new FeeRuleResource($feeRule);
    }

    public function update(Request $request, FeeRule $feeRule): FeeRuleResource
    {
        $this->authorize('update', $feeRule);

        $feeRule->fill($request->validate($this->rules($feeRule)))->save();

        return new FeeRuleResource($feeRule->fresh());
    }

    /**
     * Stop charging a fee.
     *
     * Deactivated rather than deleted: bookings that paid it reference it, and
     * an owner statement that itemises it needs the rule to still exist.
     */
    public function destroy(FeeRule $feeRule): JsonResponse
    {
        $this->authorize('delete', $feeRule);

        $feeRule->forceFill(['is_active' => false])->save();

        return response()->json([
            'message' => 'The fee is no longer charged on new bookings. Existing bookings are unchanged.',
            'data' => new FeeRuleResource($feeRule->fresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?FeeRule $fee = null): array
    {
        $creating = $fee === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'code' => [
                $creating ? 'required' : 'sometimes', 'string', 'max:48', 'alpha_dash',
                Rule::unique('fee_rules', 'code')
                    ->where('organization_id', $this->organization()->getKey())
                    ->ignore($fee?->getKey()),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'kind' => [$creating ? 'required' : 'sometimes', 'string', 'max:48'],

            'charge_basis' => [$creating ? 'required' : 'sometimes', Rule::in([
                FeeRule::PER_STAY,
                FeeRule::PER_NIGHT,
                FeeRule::PER_GUEST,
                FeeRule::PER_GUEST_PER_NIGHT,
                FeeRule::PER_PET,
                FeeRule::PER_PET_PER_NIGHT,
                FeeRule::PERCENT_OF_ACCOMMODATION,
            ])],

            // Minor units for the flat bases; `percentage` for the percentage
            // one. Exactly one of the two is meaningful for any given basis.
            'amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['sometimes', 'string', 'size:3'],

            // "First two guests included, then charge": the threshold above
            // which the per-guest bases start counting.
            'applies_after_guests' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:50'],
            'applies_after_nights' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'maximum_units' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'property_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'listing_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'portfolio_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'channels' => ['sometimes', 'nullable', 'array'],
            'channels.*' => ['string', 'max:48'],

            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],

            'is_taxable' => ['sometimes', 'boolean'],
            'is_refundable' => ['sometimes', 'boolean'],
            'is_optional' => ['sometimes', 'boolean'],
            'include_in_displayed_rate' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer'],

            // Which revenue account the fee posts to, so cleaning income and
            // pet income can be reported apart.
            'revenue_account_key' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
