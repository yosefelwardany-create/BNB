<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\People;

use App\Domain\Owners\Exceptions\OwnershipConflictException;
use App\Domain\Owners\Models\ManagementAgreement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Models\PropertyOwnership;
use App\Domain\Owners\Services\OwnerDirectory;
use App\Domain\Owners\Services\OwnershipLedger;
use App\Domain\Properties\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Resources\ManagementAgreementResource;
use App\Http\Resources\OwnerResource;
use App\Http\Resources\PropertyOwnershipResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Owners, their shares in properties, and the terms they are managed under.
 */
class OwnerController extends Controller
{
    public function __construct(
        private readonly OwnerDirectory $directory,
        private readonly OwnershipLedger $ownership,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Owner::class);

        $query = Owner::query()
            ->withCount('ownerships')
            ->search($request->string('search')->toString() ?: null);

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        if ($request->boolean('portal_enabled')) {
            $query->where('portal_enabled', true);
        }

        if ($request->filled('property_id')) {
            $query->whereHas(
                'ownerships',
                fn ($q) => $q->where('property_id', $request->string('property_id')->toString()),
            );
        }

        $sort = $request->string('sort', 'display_name')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';

        if (in_array($sort, ['display_name', 'created_at', 'status'], true)) {
            $query->orderBy($sort, $direction);
        }

        return OwnerResource::collection($query->paginate($this->perPage()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Owner::class);

        $owner = $this->directory->create($request->validate($this->rules()));

        return (new OwnerResource($owner))->response()->setStatusCode(201);
    }

    public function show(Owner $owner): OwnerResource
    {
        $this->authorize('view', $owner);

        return new OwnerResource($owner->load([
            'ownerships.property', 'agreements.property', 'tags',
        ]));
    }

    public function update(Request $request, Owner $owner): OwnerResource
    {
        $this->authorize('update', $owner);

        $data = $request->validate($this->rules($owner));

        $tags = $data['tags'] ?? null;
        unset($data['tags']);

        $this->directory->update($owner, $data);

        if ($tags !== null) {
            $owner->syncTags($tags);
        }

        return new OwnerResource($owner->fresh(['ownerships', 'tags']));
    }

    /**
     * Deactivate an owner.
     *
     * Never deleted: statements, payouts and ledger entries all reference this
     * record, and removing it would make the money that moved unexplainable.
     */
    public function destroy(Owner $owner): JsonResponse
    {
        $this->authorize('update', $owner);

        $this->directory->disablePortalAccess($owner);

        $owner->forceFill(['status' => 'inactive'])->save();

        return response()->json([
            'message' => 'The owner has been deactivated. Their statements and payout history are unchanged.',
        ]);
    }

    // ------------------------------------------------------------------
    // Ownership shares
    // ------------------------------------------------------------------

    /**
     * Who owns a property, and how much of it is unallocated.
     */
    public function propertyOwnership(Request $request, Property $property): JsonResponse
    {
        $this->authorize('viewAny', Owner::class);

        $date = $request->filled('date')
            ? CarbonImmutable::parse($request->string('date')->toString())
            : CarbonImmutable::today();

        $position = $this->ownership->positionOn($property, $date);

        return response()->json([
            'date' => $date->toDateString(),
            'data' => PropertyOwnershipResource::collection($position['shares'])->resolve(),
            'allocated_percentage' => $position['allocated'],

            // Under-allocation is legitimate — the manager may hold the
            // remainder — so it is reported rather than treated as an error.
            'unallocated_percentage' => $position['unallocated'],
        ]);
    }

    public function storeOwnership(Request $request, Owner $owner): JsonResponse
    {
        $this->authorize('manageOwnership', $owner);

        $data = $request->validate([
            'property_id' => ['required', 'string', 'exists:properties,id'],
            'ownership_percentage' => ['required', 'numeric', 'min:0.0001', 'max:100'],
            'is_primary' => ['sometimes', 'boolean'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after:starts_on'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $property = Property::query()->findOrFail($data['property_id']);

        try {
            $ownership = $this->ownership->assign($property, $owner, $data);
        } catch (OwnershipConflictException $exception) {
            // A 422 with the offending dates, so the interface can say which
            // day is over-allocated rather than "that did not work".
            return response()->json([
                'message' => $exception->getMessage(),
                'conflicts' => $exception->conflicts(),
            ], 422);
        }

        // An owner with a portal login should see a property the moment they
        // own it, without anybody remembering to update a permission.
        $this->directory->syncPortalProperties($owner);

        return (new PropertyOwnershipResource($ownership->load('property')))
            ->response()
            ->setStatusCode(201);
    }

    public function updateOwnership(Request $request, Owner $owner, PropertyOwnership $ownership): JsonResponse
    {
        $this->authorize('manageOwnership', $owner);

        abort_unless($ownership->owner_id === $owner->getKey(), 404);

        $data = $request->validate([
            'ownership_percentage' => ['sometimes', 'numeric', 'min:0.0001', 'max:100'],
            'is_primary' => ['sometimes', 'boolean'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->ownership->update($ownership, $data);
        } catch (OwnershipConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'conflicts' => $exception->conflicts(),
            ], 422);
        }

        return response()->json([
            'data' => (new PropertyOwnershipResource($updated->fresh('property')))->resolve(),
        ]);
    }

    /**
     * End a share.
     *
     * Closed with a date rather than deleted: every statement already produced
     * was attributed using it.
     */
    public function endOwnership(Request $request, Owner $owner, PropertyOwnership $ownership): JsonResponse
    {
        $this->authorize('manageOwnership', $owner);

        abort_unless($ownership->owner_id === $owner->getKey(), 404);

        $data = $request->validate([
            'ends_on' => ['sometimes', 'nullable', 'date'],
        ]);

        $this->ownership->end(
            $ownership,
            isset($data['ends_on']) ? CarbonImmutable::parse($data['ends_on']) : null,
        );

        $this->directory->syncPortalProperties($owner);

        return response()->json([
            'data' => (new PropertyOwnershipResource($ownership->fresh('property')))->resolve(),
            'message' => 'The share has been closed. Statements already produced are unchanged.',
        ]);
    }

    // ------------------------------------------------------------------
    // Portal access
    // ------------------------------------------------------------------

    public function enablePortal(Request $request, Owner $owner): JsonResponse
    {
        $this->authorize('managePortal', $owner);

        $data = $request->validate([
            'send_invitation' => ['sometimes', 'boolean'],
        ]);

        if (blank($owner->email)) {
            return response()->json([
                'message' => 'This owner needs an email address before they can be given portal access.',
            ], 422);
        }

        $result = $this->directory->enablePortalAccess(
            $owner,
            $data['send_invitation'] ?? true,
        );

        return response()->json([
            'data' => (new OwnerResource($result['owner']))->resolve(),
            'invitation_sent' => $result['invitation_sent'],
            'message' => $result['invitation_sent']
                ? 'Portal access granted. The owner has been sent a link to set their password.'
                : 'Portal access granted using their existing account.',
        ]);
    }

    public function disablePortal(Owner $owner): JsonResponse
    {
        $this->authorize('managePortal', $owner);

        return response()->json([
            'data' => (new OwnerResource($this->directory->disablePortalAccess($owner)))->resolve(),
            'message' => 'Portal access withdrawn. The account itself is untouched.',
        ]);
    }

    // ------------------------------------------------------------------
    // Management agreements
    // ------------------------------------------------------------------

    public function agreements(Owner $owner): AnonymousResourceCollection
    {
        $this->authorize('view', $owner);

        return ManagementAgreementResource::collection(
            $owner->agreements()->with('property')->orderByDesc('starts_on')->get(),
        );
    }

    public function storeAgreement(Request $request, Owner $owner): JsonResponse
    {
        $this->authorize('manageOwnership', $owner);

        $data = $request->validate($this->agreementRules());

        $agreement = new ManagementAgreement;
        $agreement->fill($data);
        $agreement->organization_id = $this->organization()->getKey();
        $agreement->owner_id = $owner->getKey();
        $agreement->currency ??= $owner->payout_currency ?? $this->organization()->base_currency;
        $agreement->save();

        return (new ManagementAgreementResource($agreement))->response()->setStatusCode(201);
    }

    public function updateAgreement(
        Request $request,
        Owner $owner,
        ManagementAgreement $agreement,
    ): ManagementAgreementResource {
        $this->authorize('manageOwnership', $owner);

        abort_unless($agreement->owner_id === $owner->getKey(), 404);

        $agreement->fill($request->validate($this->agreementRules($agreement)))->save();

        return new ManagementAgreementResource($agreement->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Owner $owner = null): array
    {
        $creating = $owner === null;

        return [
            'type' => ['sometimes', Rule::in(['individual', 'company'])],
            // A company needs a company name; a person needs a first name.
            // Either way something must identify them on a statement.
            'first_name' => [
                Rule::requiredIf(fn (): bool => $creating && request()->input('type', 'individual') !== 'company'),
                'nullable', 'string', 'max:80',
            ],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'company_name' => [
                Rule::requiredIf(fn (): bool => $creating && request()->input('type') === 'company'),
                'nullable', 'string', 'max:160',
            ],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:160'],

            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'language' => ['sometimes', 'nullable', 'string', 'max:12'],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone'],

            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],

            'tax_identifier' => ['sometimes', 'nullable', 'string', 'max:64'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:64'],

            'payout_currency' => ['sometimes', 'nullable', 'string', 'size:3', Rule::in(config('pms.currencies'))],
            'payout_method' => ['sometimes', 'nullable', Rule::in(['bank_transfer', 'cheque', 'manual'])],

            'bank_account_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'bank_routing_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'bank_iban' => ['sometimes', 'nullable', 'string', 'max:64'],
            'bank_swift' => ['sometimes', 'nullable', 'string', 'max:32'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'bank_country' => ['sometimes', 'nullable', 'string', 'size:2'],

            'statement_frequency' => [
                'sometimes',
                Rule::in(['weekly', 'fortnightly', 'monthly', 'quarterly', 'annually']),
            ],
            'statement_day' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'reserve_amount' => ['sometimes', 'integer', 'min:0'],

            'status' => ['sometimes', Rule::in(['active', 'inactive', 'prospective'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:60'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function agreementRules(?ManagementAgreement $agreement = null): array
    {
        $creating = $agreement === null;

        return [
            'property_id' => ['sometimes', 'nullable', 'string', 'exists:properties,id'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:64'],

            'commission_model' => [
                $creating ? 'required' : 'sometimes',
                Rule::in([
                    ManagementAgreement::PERCENT_OF_REVENUE,
                    ManagementAgreement::PERCENT_OF_NET,
                    ManagementAgreement::FIXED_MONTHLY,
                    ManagementAgreement::FIXED_PER_BOOKING,
                    ManagementAgreement::PER_NIGHT,
                    ManagementAgreement::TIERED,
                ]),
            ],
            'commission_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'commission_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'commission_tiers' => ['sometimes', 'nullable', 'array'],
            'commission_tiers.*.up_to' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'commission_tiers.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],

            'commission_on_accommodation' => ['sometimes', 'boolean'],
            'commission_on_fees' => ['sometimes', 'boolean'],
            'commission_on_taxes' => ['sometimes', 'boolean'],
            'deduct_channel_commission_first' => ['sometimes', 'boolean'],
            'deduct_payment_fees_first' => ['sometimes', 'boolean'],

            'owner_pays_cleaning' => ['sometimes', 'boolean'],
            'owner_pays_maintenance' => ['sometimes', 'boolean'],
            'owner_pays_supplies' => ['sometimes', 'boolean'],
            'maintenance_markup_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'maintenance_approval_threshold' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'owner_stay_nights_included' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'charge_cleaning_for_owner_stays' => ['sometimes', 'boolean'],

            'starts_on' => [$creating ? 'required' : 'sometimes', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after:starts_on'],
            'notice_period_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'ended', 'terminated'])],
            'terms' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
    }
}
