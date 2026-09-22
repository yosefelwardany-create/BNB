<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Http\Resources\VendorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * External contractors.
 */
class VendorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Vendor::class);

        $query = Vendor::query();

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        if ($request->filled('category')) {
            $query->forCategory($request->string('category')->toString());
        }

        // "Who can I actually send today" is a different question from "who is
        // on the list", and an expired insurance certificate is the usual
        // difference between the two.
        if ($request->boolean('dispatchable_only')) {
            $query->active()->where(function ($q): void {
                $q->whereNull('insurance_expires_on')
                    ->orWhere('insurance_expires_on', '>=', now()->toDateString());
            });
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';

            $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', $term)
                    ->orWhere('contact_name', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term);
            });
        }

        return VendorResource::collection($query->orderBy('name')->paginate($this->perPage()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Vendor::class);

        $vendor = new Vendor;
        $vendor->fill($request->validate($this->rules()));
        $vendor->organization_id = $this->organization()->getKey();
        $vendor->currency ??= $this->organization()->base_currency;
        $vendor->save();

        return (new VendorResource($vendor))->response()->setStatusCode(201);
    }

    public function show(Vendor $vendor): VendorResource
    {
        $this->authorize('view', $vendor);

        return new VendorResource($vendor);
    }

    public function update(Request $request, Vendor $vendor): VendorResource
    {
        $this->authorize('update', $vendor);

        $vendor->fill($request->validate($this->rules($vendor)))->save();

        return new VendorResource($vendor->fresh());
    }

    /**
     * Deactivate a contractor.
     *
     * Soft-deleted rather than removed: their jobs, their costs and the
     * expenses raised against them all reference this record.
     */
    public function destroy(Vendor $vendor): JsonResponse
    {
        $this->authorize('delete', $vendor);

        $vendor->forceFill(['is_active' => false])->save();
        $vendor->delete();

        return response()->json([
            'message' => 'The contractor has been deactivated. Their work history is unchanged.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Vendor $vendor = null): array
    {
        $creating = $vendor === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'category' => [$creating ? 'required' : 'sometimes', 'string', 'max:48'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'tax_identifier' => ['sometimes', 'nullable', 'string', 'max:64'],
            'hourly_rate' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'callout_fee' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'insurance_expires_on' => ['sometimes', 'nullable', 'date'],
            'certifications' => ['sometimes', 'nullable', 'array'],
            'certifications.*' => ['string', 'max:120'],
            'rating' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:5'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
