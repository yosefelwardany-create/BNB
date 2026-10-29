<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Domain\Websites\DataObjects\SearchCriteria;
use App\Domain\Websites\Services\WebsiteCatalogue;
use App\Domain\Websites\Services\WebsiteQuotes;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The booking box's questions: which nights are open, and what a stay costs.
 *
 * Answered for a live site's own listings only, like its pages.
 */
class SiteBookingController extends Controller
{
    /** How far ahead a calendar can be opened. */
    private const CALENDAR_MONTHS_AHEAD = 24;

    public function __construct(
        private readonly WebsiteCatalogue $catalogue,
        private readonly WebsiteQuotes $quotes,
    ) {}

    public function calendar(Request $request, string $site, string $listing): JsonResponse
    {
        $found = $this->catalogue->listing($this->catalogue->live($site), $listing);

        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $thisMonth = WebsiteQuotes::localToday($found)->startOfMonth();
        $month = isset($validated['month'])
            ? CarbonImmutable::createFromFormat('!Y-m', $validated['month'])->startOfMonth()
            : $thisMonth;

        // Compared as months: $thisMonth is on the home's clock, $month on none.
        if ($month->format('Y-m') > $thisMonth->addMonthsNoOverflow(self::CALENDAR_MONTHS_AHEAD)->format('Y-m')) {
            throw ValidationException::withMessages(['month' => 'Choose a month within the next two years.']);
        }

        // A month gone by is shown from this month: there is nothing to book in it.
        return response()->json([
            'days' => $this->quotes->calendar($found, $month->format('Y-m') < $thisMonth->format('Y-m') ? $thisMonth : $month),
        ]);
    }

    public function quote(Request $request, string $site, string $listing): JsonResponse
    {
        $found = $this->catalogue->listing($this->catalogue->live($site), $listing);

        $validated = $request->validate([
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:50'],
            'children' => ['nullable', 'integer', 'min:0', 'max:50'],
        ], [
            'check_out.after' => 'Check-out must be after check-in.',
        ]);

        $checkIn = CarbonImmutable::createFromFormat('!Y-m-d', $validated['check_in']);
        $checkOut = CarbonImmutable::createFromFormat('!Y-m-d', $validated['check_out']);

        // Past by the home's own date, not the server's.
        if ($checkIn->toDateString() < WebsiteQuotes::localToday($found)->toDateString()) {
            throw ValidationException::withMessages(['check_in' => 'Check-in cannot be in the past.']);
        }

        if ($checkIn->diffInDays($checkOut) > SearchCriteria::MAX_NIGHTS) {
            throw ValidationException::withMessages([
                'check_out' => sprintf('Stays can be up to %d nights.', SearchCriteria::MAX_NIGHTS),
            ]);
        }

        $outcome = $this->quotes->quote($found, $checkIn, $checkOut, (int) $validated['adults'], (int) ($validated['children'] ?? 0));

        if ($outcome['quote'] === null) {
            return response()->json([
                'message' => $outcome['reasons'][0] ?? 'Those dates are not available.',
                'available' => false,
                'reasons' => $outcome['reasons'],
            ], 422);
        }

        return response()->json(['available' => true] + $this->quotes->present($outcome['quote']));
    }
}
