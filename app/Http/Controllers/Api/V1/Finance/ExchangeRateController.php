<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\Accounting\Models\ExchangeRate;
use App\Domain\Accounting\Services\CurrencyConverter;
use App\Domain\Integrations\Registries\ExchangeRateProviderRegistry;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The rates this platform converts at.
 *
 * Rates are a fact about the world rather than about a tenant, so the table is
 * shared and reading it needs only the ability to see financials. Writing is
 * gated harder: a wrong rate silently misstates every figure derived from it, in
 * every organization, until somebody notices.
 */
class ExchangeRateController extends Controller
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    public function index(Request $request): JsonResponse
    {
        $query = ExchangeRate::query();

        if ($request->filled('base')) {
            $query->where('base_currency', strtoupper($request->string('base')->toString()));
        }

        if ($request->filled('quote')) {
            $query->where('quote_currency', strtoupper($request->string('quote')->toString()));
        }

        if ($request->filled('since')) {
            $query->where('rate_date', '>=', $request->date('since'));
        }

        $rates = $query->orderByDesc('rate_date')->paginate($this->perPage());

        $provider = app(ExchangeRateProviderRegistry::class)->default();

        return response()->json([
            'data' => collect($rates->items())->map(fn (ExchangeRate $rate): array => [
                'id' => $rate->id,
                'base_currency' => $rate->base_currency,
                'quote_currency' => $rate->quote_currency,
                'rate_date' => $rate->rate_date?->toDateString(),
                // A string, keeping the precision the column exists to preserve.
                'rate' => (string) $rate->rate,
                'source' => $rate->source,
            ])->values(),
            'meta' => [
                'current_page' => $rates->currentPage(),
                'last_page' => $rates->lastPage(),
                'total' => $rates->total(),
                // The honesty flag, as everywhere else: nobody is subscribing to
                // a market feed on the customer's behalf.
                'provider' => $provider->key(),
                'is_live' => $provider->isLive(),
                'simulation_reason' => $provider->isLive() ? null : $provider->simulationReason(),
            ],
        ]);
    }

    /**
     * Record or correct a rate for a day.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base_currency' => ['required', 'string', 'size:3'],
            'quote_currency' => ['required', 'string', 'size:3', 'different:base_currency'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'rate_date' => ['sometimes', 'date'],
            'source' => ['sometimes', 'string', 'max:32'],
        ]);

        $rate = $this->converter->record(
            $data['base_currency'],
            $data['quote_currency'],
            $data['rate'],
            isset($data['rate_date']) ? CarbonImmutable::parse($data['rate_date']) : null,
            $data['source'] ?? 'manual',
        );

        return response()->json([
            'message' => 'The rate has been recorded.',
            'data' => [
                'base_currency' => $rate->base_currency,
                'quote_currency' => $rate->quote_currency,
                'rate_date' => $rate->rate_date?->toDateString(),
                'rate' => (string) $rate->rate,
                'source' => $rate->source,
            ],
        ], 201);
    }

    /**
     * What a rate would be, without recording anything.
     *
     * Answers null rather than a guess when the pair is unknown, and says so —
     * an interface that showed 1.0 here would teach somebody to trust it.
     */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'size:3'],
            'to' => ['required', 'string', 'size:3'],
            'on' => ['sometimes', 'date'],
            'amount' => ['sometimes', 'integer'],
        ]);

        $on = isset($data['on']) ? CarbonImmutable::parse($data['on']) : null;
        $rate = $this->converter->rateFor($data['from'], $data['to'], $on);

        return response()->json([
            'data' => [
                'from' => strtoupper($data['from']),
                'to' => strtoupper($data['to']),
                'on' => ($on ?? CarbonImmutable::today())->toDateString(),
                'rate' => $rate,
                'known' => $rate !== null,
                'converted' => $rate === null || ! isset($data['amount'])
                    ? null
                    : (int) round($data['amount'] * $rate),
            ],
        ]);
    }
}
