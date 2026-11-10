<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Domain\Availability\Models\CalendarDay;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Change nights" over a long range, as staff use it for a season.
 *
 * Written night by night, half a year was two queries a night: about three
 * hundred round trips to a database that is not next door, a minute or more,
 * and a request that ran out its time behind the proxy while the dialog sat
 * open. The range is read once and written in bulk.
 */
class CalendarRangeEditTest extends TestCase
{
    use RefreshDatabase;

    private string $listing;

    protected function setUp(): void
    {
        parent::setUp();

        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $this->postJson('/api/v1/properties', [
            'name' => 'Twin Room',
            'property_type' => 'apartment',
            'address_line_1' => '12 Test Street',
            'city' => 'London',
            'country_code' => 'GB',
            'max_occupancy' => 2,
            'base_rate' => 9500,
        ])->assertCreated();

        $this->listing = (string) $this->getJson('/api/v1/listings')->assertOk()->json('data.0.id');
    }

    public function test_half_a_year_is_written_in_a_handful_of_queries(): void
    {
        $from = CarbonImmutable::today()->addMonths(2);
        $to = $from->addDays(152);
        $queries = 0;

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'calendar_days')) {
                $queries++;
            }
        });

        $this->edit($from, $to, ['rate_override' => 43200, 'minimum_nights' => 2, 'closed_to_arrival' => false])
            ->assertOk()
            ->assertJsonPath('updated', 153);

        $this->assertLessThanOrEqual(5, $queries, 'A season is one read and a bulk write, not two queries a night.');

        $days = $this->days();
        $this->assertCount(153, $days);
        $this->assertSame([43200], $days->pluck('rate_override')->unique()->values()->all());
        $this->assertSame([2], $days->pluck('minimum_nights')->unique()->values()->all());
        $this->assertSame($from->toDateString(), $days->first()->calendar_date->toDateString());
        $this->assertSame($to->toDateString(), $days->last()->calendar_date->toDateString());
    }

    public function test_a_night_already_changed_keeps_what_this_edit_does_not_touch(): void
    {
        $from = CarbonImmutable::today()->addDays(20);

        $this->edit($from, $from->addDays(4), ['rate_override' => 17500, 'note' => 'Christmas'])->assertOk();
        $before = $this->days()->keyBy(fn (CalendarDay $day): string => $day->calendar_date->toDateString());

        $this->edit($from->addDays(2), $from->addDays(6), ['minimum_nights' => 3])
            ->assertOk()
            ->assertJsonPath('updated', 5);

        $after = $this->days()->keyBy(fn (CalendarDay $day): string => $day->calendar_date->toDateString());
        $this->assertCount(7, $after);

        // Nights in both edits: the same row, its price and note kept.
        $both = $from->addDays(3)->toDateString();
        $this->assertSame($before[$both]->getKey(), $after[$both]->getKey());
        $this->assertSame(17500, $after[$both]->rate_override);
        $this->assertSame('Christmas', $after[$both]->note);
        $this->assertSame(3, $after[$both]->minimum_nights);

        // Nights only in the second: a minimum stay and nothing else.
        $later = $from->addDays(6)->toDateString();
        $this->assertNull($after[$later]->rate_override);
        $this->assertSame(3, $after[$later]->minimum_nights);
    }

    public function test_nights_put_back_to_the_defaults_leave_no_row(): void
    {
        $from = CarbonImmutable::today()->addDays(20);

        $this->edit($from, $from->addDays(4), ['rate_override' => 17500, 'is_blocked' => true])->assertOk();
        $this->assertCount(5, $this->days());

        $this->edit($from->addDays(1), $from->addDays(3), ['rate_override' => null, 'is_blocked' => false])
            ->assertOk()
            ->assertJsonPath('updated', 3);

        $this->assertSame(
            [$from->toDateString(), $from->addDays(4)->toDateString()],
            $this->days()->map(fn (CalendarDay $day): string => $day->calendar_date->toDateString())->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function edit(CarbonImmutable $first, CarbonImmutable $last, array $changes): TestResponse
    {
        return $this->patchJson("/api/v1/calendar/listings/{$this->listing}/days", [
            'from' => $first->toDateString(),
            'to' => $last->toDateString(),
        ] + $changes);
    }

    /**
     * @return Collection<int, CalendarDay>
     */
    private function days(): Collection
    {
        return $this->app->make(TenantContext::class)->withoutScope(fn () => CalendarDay::query()
            ->where('listing_id', $this->listing)
            ->orderBy('calendar_date')
            ->get());
    }
}
