<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Revenue;

use App\Domain\Pricing\Models\TaxRule;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaxRuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What the state takes.
 *
 * Two things here are unlike the rest of pricing.
 *
 * **`channels_collecting` decides whether we charge the tax at all.** Several
 * OTAs remit occupancy tax themselves in several jurisdictions. On those
 * bookings the platform must not charge it again, and must not report it as
 * owed — doing either means either double-taxing the guest or telling the
 * operator they owe money somebody else has already paid.
 *
 * **A tax rule is never deleted, and barely ever edited.** What was owed on a
 * booking is fixed by the rules in force on the day it was taken, and a
 * jurisdiction changing its rate is a new rule with a start date, not an edit
 * to the old one. Editing in place would silently restate every historic
 * booking's tax liability.
 */
class TaxRuleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TaxRule::class);

        $query = TaxRule::query();

        if ($request->filled('property_id')) {
            $property = $request->string('property_id')->toString();

            $query->where(fn ($q) => $q->where('property_id', $property)->orWhereNull('property_id'));
        }

        if ($request->filled('country_code')) {
            $query->where('country_code', strtoupper($request->string('country_code')->toString()));
        }

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        // Which rules were in force on a given date — the question asked when
        // reconciling a historic return.
        if ($request->filled('on')) {
            $on = $request->date('on')->toDateString();

            $query->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $on))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $on));
        }

        return TaxRuleResource::collection(
            $query->orderBy('priority')->orderBy('name')->paginate($this->perPage()),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', TaxRule::class);

        $data = $request->validate($this->rules());

        $tax = new TaxRule;
        $tax->fill($data);
        $tax->organization_id = $this->organization()->getKey();
        $tax->currency ??= $this->organization()->base_currency;
        $tax->save();

        return (new TaxRuleResource($tax))->response()->setStatusCode(201);
    }

    public function show(TaxRule $taxRule): TaxRuleResource
    {
        $this->authorize('view', $taxRule);

        return new TaxRuleResource($taxRule);
    }

    /**
     * Amend a tax rule.
     *
     * A rate change should normally be a *new* rule with a start date rather
     * than an edit, because what was owed on a past booking is fixed by the
     * rules in force then. This endpoint therefore refuses to change the rate
     * or the calculation basis of a rule that has already priced something;
     * the descriptive fields stay editable, since correcting a name or a
     * remittance reference restates nothing.
     */
    public function update(Request $request, TaxRule $taxRule): TaxRuleResource
    {
        $this->authorize('update', $taxRule);

        $data = $request->validate($this->rules($taxRule));

        $changesLiability = array_intersect_key($data, array_flip([
            'calculation', 'rate', 'amount', 'applies_to_accommodation',
            'applies_to_fees', 'compounds_on_taxes', 'effective_from',
        ])) !== [];

        if ($changesLiability && $this->hasPricedSomething($taxRule)) {
            abort(422, sprintf(
                'Tax rule %s has already been applied to bookings. '
                .'End it with an effective_to date and create a new rule for the new rate, '
                .'so historic liabilities are not restated.',
                $taxRule->code,
            ));
        }

        $taxRule->fill($data)->save();

        return new TaxRuleResource($taxRule->fresh());
    }

    /**
     * Stop applying a tax.
     *
     * Ended rather than deleted, with today as the last day it applied. The
     * rule remains in force for every booking taken while it was live, which
     * is exactly what a tax authority will ask about.
     */
    public function destroy(TaxRule $taxRule): JsonResponse
    {
        $this->authorize('delete', $taxRule);

        $taxRule->forceFill([
            'is_active' => false,
            'effective_to' => $taxRule->effective_to ?? now()->toDateString(),
        ])->save();

        return response()->json([
            'message' => 'The tax rule has been ended. Bookings taken while it applied are unchanged.',
            'data' => new TaxRuleResource($taxRule->fresh()),
        ]);
    }

    /**
     * Whether this rule has been applied to a real booking.
     */
    private function hasPricedSomething(TaxRule $taxRule): bool
    {
        return DB::table('reservation_charges')
            ->where('tax_rule_id', $taxRule->getKey())
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?TaxRule $tax = null): array
    {
        $creating = $tax === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'code' => [
                $creating ? 'required' : 'sometimes', 'string', 'max:48', 'alpha_dash',
                Rule::unique('tax_rules', 'code')
                    ->where('organization_id', $this->organization()->getKey())
                    ->ignore($tax?->getKey()),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'calculation' => [$creating ? 'required' : 'sometimes', Rule::in([
                TaxRule::PERCENT,
                TaxRule::FIXED_PER_STAY,
                TaxRule::FIXED_PER_NIGHT,
                TaxRule::FIXED_PER_GUEST,
                TaxRule::FIXED_PER_GUEST_PER_NIGHT,
            ])],
            'rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],

            'applies_to_accommodation' => ['sometimes', 'boolean'],
            'applies_to_fees' => ['sometimes', 'boolean'],
            'applies_to_fee_codes' => ['sometimes', 'nullable', 'array'],
            'applies_to_fee_codes.*' => ['string', 'max:48'],
            'compounds_on_taxes' => ['sometimes', 'boolean'],

            // Long-stay exemptions are ordinary in city tourist taxes.
            'maximum_nights' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'exempt_after_nights' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'exempt_guest_age_under' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:30'],
            'maximum_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'property_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'portfolio_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],

            // Channels that remit this tax themselves. Getting this wrong
            // double-taxes the guest or overstates what the operator owes.
            'channels_collecting' => ['sometimes', 'nullable', 'array'],
            'channels_collecting.*' => ['string', 'max:48'],
            'channels' => ['sometimes', 'nullable', 'array'],
            'channels.*' => ['string', 'max:48'],

            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
            'priority' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'remittance_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
