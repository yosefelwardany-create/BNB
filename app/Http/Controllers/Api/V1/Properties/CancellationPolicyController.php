<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Properties;

use App\Domain\Properties\Models\CancellationPolicy;
use App\Http\Controllers\Controller;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CancellationPolicyController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('properties.view');

        return response()->json([
            'data' => CancellationPolicy::query()->active()->orderBy('name')->get()
                ->map(fn (CancellationPolicy $policy): array => $this->present($policy))
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('properties.update');

        $data = $request->validate($this->rules());

        $policy = CancellationPolicy::query()->create($data);

        return response()->json(['data' => $this->present($policy)], 201);
    }

    public function update(Request $request, CancellationPolicy $policy): JsonResponse
    {
        $this->authorize('properties.update');

        $policy->update($request->validate($this->rules($policy)));

        return response()->json(['data' => $this->present($policy)]);
    }

    /**
     * Show what a policy would refund for a given cancellation.
     *
     * Exposed as its own endpoint so an agent can answer "what would I get
     * back?" before committing to anything, and so the arithmetic shown to a
     * guest is the same arithmetic the cancellation itself performs.
     */
    public function preview(Request $request, CancellationPolicy $policy): JsonResponse
    {
        $this->authorize('properties.view');

        $data = $request->validate([
            'arrival_date' => ['required', 'date'],
            'cancellation_date' => ['sometimes', 'date'],
            'accommodation' => ['required', 'integer', 'min:0'],
            'cleaning_fee' => ['sometimes', 'integer', 'min:0'],
            'taxes' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $currency = strtoupper($data['currency'] ?? $this->organization()->base_currency);

        $quote = $policy->quote(
            cancellationMoment: CarbonImmutable::parse($data['cancellation_date'] ?? now()),
            arrival: CarbonImmutable::parse($data['arrival_date']),
            accommodation: Money::of($data['accommodation'], $currency),
            cleaningFee: Money::of($data['cleaning_fee'] ?? 0, $currency),
            taxes: Money::of($data['taxes'] ?? 0, $currency),
        );

        return response()->json([
            'refund' => $quote['refund']->jsonSerialize(),
            'retained' => $quote['retained']->jsonSerialize(),
            'refund_percent' => $quote['refund_percent'],
            'days_before_arrival' => $quote['days_before_arrival'],
            'breakdown' => $quote['breakdown'],
            'explanation' => $quote['explanation'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CancellationPolicy $policy): array
    {
        return [
            'id' => $policy->getKey(),
            'name' => $policy->name,
            'slug' => $policy->slug,
            'description' => $policy->description,
            'free_cancellation_days' => (int) $policy->free_cancellation_days,
            'tiers' => $policy->tiers,
            'refund_cleaning_fee' => $policy->refund_cleaning_fee,
            'refund_taxes' => $policy->refund_taxes,
            'is_default' => $policy->is_default,
            'is_active' => $policy->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?CancellationPolicy $policy = null): array
    {
        return [
            'name' => [$policy === null ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'free_cancellation_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'tiers' => [$policy === null ? 'required' : 'sometimes', 'array', 'min:1'],
            'tiers.*.days_before' => ['required', 'integer', 'min:0', 'max:365'],
            'tiers.*.refund_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'refund_cleaning_fee' => ['sometimes', 'boolean'],
            'refund_taxes' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
