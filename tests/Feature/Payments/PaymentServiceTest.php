<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\DefaultChartOfAccounts as Accounts;
use App\Domain\Listings\Models\Listing;
use App\Domain\Payments\Enums\PaymentKind;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Taking money and giving it back.
 *
 * The bundled processor is a real local implementation, not a stub: it keeps
 * state and enforces the same invariants a real one does. That is what makes
 * these tests worth anything — they exercise the actual capture, refund and
 * reconciliation paths rather than asserting against a mock that was told what
 * to say.
 *
 * What they protect:
 *
 *  - A capture cannot exceed its authorisation, and a refund cannot exceed its
 *    capture. Refused by the service, by the processor, and by the database.
 *  - A retry does not take the money twice.
 *  - Every movement lands in the ledger, balanced, and a security deposit
 *    lands as a liability rather than as revenue.
 *  - Nothing claims to be real when it is not.
 */
class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $payments;

    private JournalPoster $poster;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = $this->app->make(PaymentService::class);
        $this->poster = $this->app->make(JournalPoster::class);

        $organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 5000,
            'max_occupancy' => 4,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);
    }

    public function test_an_authorisation_holds_funds_without_posting_anything(): void
    {
        $payment = $this->payments->authorize($this->eur(35000), $this->book());

        $this->assertSame(PaymentStatus::Authorized, $payment->status);
        $this->assertNotNull($payment->provider_reference);
        $this->assertSame(0, (int) $payment->captured_amount);

        // No money has moved. Recognising an authorisation would overstate
        // cash by every unarrived booking.
        $this->assertTrue($this->cashBalance()->isZero());
    }

    public function test_capturing_moves_money_and_posts_a_balanced_entry(): void
    {
        $reservation = $this->book();

        $payment = $this->payments->authorize($this->eur(35000), $reservation);
        $captured = $this->payments->capture($payment, $this->eur(35000));

        $this->assertSame(PaymentStatus::Captured, $captured->status);
        $this->assertSame(35000, (int) $captured->captured_amount);

        $this->assertSame(35000, $this->cashBalance()->minorUnits);
        $this->assertTrue($this->poster->trialBalance()->isZero());
    }

    public function test_a_capture_larger_than_the_authorisation_is_refused(): void
    {
        $payment = $this->payments->authorize($this->eur(10000), $this->book());

        try {
            $this->payments->capture($payment, $this->eur(15000));

            $this->fail('Capturing more than was authorised should have been refused.');
        } catch (PaymentException $exception) {
            $this->assertSame('exceeds_authorization', $exception->failureCode);
        }

        // Refused before the processor was asked, so nothing moved anywhere.
        $this->assertSame(0, (int) $payment->fresh()->captured_amount);
        $this->assertTrue($this->cashBalance()->isZero());
    }

    public function test_a_partial_capture_leaves_the_rest_available(): void
    {
        $payment = $this->payments->authorize($this->eur(35000), $this->book());

        $payment = $this->payments->capture($payment, $this->eur(10000));

        $this->assertSame(10000, (int) $payment->captured_amount);
        $this->assertSame(25000, $payment->capturableAmount()->minorUnits);

        // A deposit then a balance: two captures against one hold.
        $payment = $this->payments->capture($payment, $this->eur(25000));

        $this->assertSame(35000, (int) $payment->captured_amount);
        $this->assertSame(35000, $this->cashBalance()->minorUnits);
    }

    public function test_a_processing_fee_is_recorded_as_an_expense_not_netted_away(): void
    {
        $payment = $this->payments->authorize($this->eur(10000), $this->book());

        $this->payments->capture($payment, $this->eur(10000), ['fee_amount' => 290]);

        // Only the net reached the bank...
        $this->assertSame(9710, $this->cashBalance()->minorUnits);

        // ...but the cost of taking the money is visible as an expense rather
        // than silently disappearing into a smaller receipt.
        $this->assertSame(
            290,
            $this->poster->balanceOf($this->poster->account(Accounts::PAYMENT_PROCESSING))->minorUnits,
        );

        $this->assertTrue($this->poster->trialBalance()->isZero());
    }

    public function test_a_refund_cannot_exceed_what_was_captured(): void
    {
        $payment = $this->payments->authorize($this->eur(10000), $this->book());
        $payment = $this->payments->capture($payment, $this->eur(10000));

        try {
            $this->payments->refund($payment, $this->eur(15000));

            $this->fail('Refunding more than was captured should have been refused.');
        } catch (PaymentException $exception) {
            $this->assertSame('exceeds_capture', $exception->failureCode);
        }

        $this->assertSame(0, (int) $payment->fresh()->refunded_amount);
    }

    public function test_a_full_refund_returns_the_ledger_to_where_it_started(): void
    {
        $payment = $this->payments->authorize($this->eur(10000), $this->book());
        $payment = $this->payments->capture($payment, $this->eur(10000));

        $refund = $this->payments->refund($payment, $this->eur(10000), 'cancellation');

        $this->assertSame('completed', $refund->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);

        $this->assertTrue($this->cashBalance()->isZero());
        $this->assertTrue($this->poster->trialBalance()->isZero());
    }

    public function test_a_partial_refund_leaves_the_payment_partially_refunded(): void
    {
        $payment = $this->payments->authorize($this->eur(10000), $this->book());
        $payment = $this->payments->capture($payment, $this->eur(10000));

        $this->payments->refund($payment, $this->eur(3000), 'goodwill');

        $payment->refresh();

        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        $this->assertSame(3000, (int) $payment->refunded_amount);
        $this->assertSame(7000, $payment->refundableAmount()->minorUnits);
        $this->assertSame(7000, $this->cashBalance()->minorUnits);
    }

    public function test_refunding_twice_cannot_exceed_the_capture_in_total(): void
    {
        $payment = $this->payments->authorize($this->eur(10000), $this->book());
        $payment = $this->payments->capture($payment, $this->eur(10000));

        $this->payments->refund($payment, $this->eur(6000));

        $this->expectException(PaymentException::class);

        // 6000 + 5000 exceeds the 10000 captured, even though neither does
        // alone.
        $this->payments->refund($payment->fresh(), $this->eur(5000));
    }

    public function test_a_security_deposit_is_a_liability_and_never_revenue(): void
    {
        $reservation = $this->book();

        $payment = $this->payments->charge($this->eur(20000), $reservation, [
            'kind' => PaymentKind::SecurityDeposit,
            'description' => 'Damage deposit',
        ]);

        $this->assertSame(PaymentStatus::Captured, $payment->status);

        // The guest's money, held. Treating it as income would overstate
        // revenue and understate what is owed back.
        $this->assertSame(
            20000,
            $this->poster->balanceOf($this->poster->account(Accounts::SECURITY_DEPOSITS_HELD))->minorUnits,
        );

        $this->assertTrue(
            $this->poster->balanceOf($this->poster->account(Accounts::ACCOMMODATION_REVENUE))->isZero(),
        );
    }

    public function test_releasing_a_security_deposit_discharges_the_liability(): void
    {
        $payment = $this->payments->charge($this->eur(20000), $this->book(), [
            'kind' => PaymentKind::SecurityDeposit,
        ]);

        $this->payments->refund($payment, $this->eur(20000), 'damage_release');

        // Nothing to reverse out of income, because it was never there.
        $this->assertTrue(
            $this->poster->balanceOf($this->poster->account(Accounts::SECURITY_DEPOSITS_HELD))->isZero(),
        );

        $this->assertTrue(
            $this->poster->balanceOf($this->poster->account(Accounts::REFUNDS))->isZero(),
        );
    }

    public function test_a_channel_collected_payment_recognises_revenue_without_inventing_cash(): void
    {
        $payment = $this->payments->charge($this->eur(30000), $this->book(), [
            'is_collected_by_us' => false,
            'method' => 'channel_collected',
        ]);

        $this->assertSame(PaymentStatus::Captured, $payment->status);

        // The guest has paid, but the money is with the channel. Posting it as
        // cash would inflate the bank balance by every OTA booking.
        $this->assertTrue($this->cashBalance()->isZero());
        $this->assertTrue($this->poster->trialBalance()->isZero());
    }

    public function test_a_capture_retried_with_the_same_amount_does_not_take_it_twice(): void
    {
        $reservation = $this->book();

        $payment = $this->payments->authorize($this->eur(10000), $reservation);

        $this->payments->capture($payment, $this->eur(10000));

        // A timeout, a redelivered job, an impatient operator: the second
        // attempt must be the first one's answer, not a second charge. This is
        // the one failure here that is not recoverable.
        $second = $this->payments->capture($payment->fresh(), $this->eur(10000));

        $this->assertSame(10000, (int) $second->captured_amount);
        $this->assertSame(10000, $this->cashBalance()->minorUnits);
    }

    public function test_a_declined_card_records_the_failure_and_does_not_post(): void
    {
        // The bundled processor declines amounts ending in 51 — a scripted
        // outcome, so the failure path is genuinely exercised rather than
        // asserted against a mock.
        try {
            $this->payments->charge($this->eur(10051), $this->book());

            $this->fail('A declined card should have raised.');
        } catch (PaymentException $exception) {
            $this->assertNotNull($exception->failureCode);
        }

        $payment = Payment::query()->latest('created_at')->first();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertNotNull($payment->failure_message);
        $this->assertTrue($this->cashBalance()->isZero());
    }

    public function test_a_voided_authorisation_posts_nothing(): void
    {
        $payment = $this->payments->authorize($this->eur(10000), $this->book());

        $voided = $this->payments->void($payment, 'Guest paid by transfer instead');

        $this->assertSame(PaymentStatus::Voided, $voided->status);

        // An authorisation that was never captured moved no money, so there is
        // nothing in the ledger to undo.
        $this->assertTrue($this->poster->trialBalance()->isZero());
        $this->assertTrue($this->cashBalance()->isZero());
    }

    public function test_a_payment_reports_honestly_that_the_processor_is_simulated(): void
    {
        $payment = $this->payments->charge($this->eur(10000), $this->book());

        // The bundled processor is real and working, but no money moves. Every
        // surface that shows this payment reads the flag.
        $this->assertTrue((bool) $payment->is_simulated);
    }

    public function test_capturing_updates_what_the_booking_has_been_paid(): void
    {
        $reservation = $this->book();

        $this->assertSame(0, (int) $reservation->paid_total);

        $payment = $this->payments->authorize($this->eur(15000), $reservation);
        $this->payments->capture($payment, $this->eur(15000));

        $reservation->refresh();

        $this->assertSame(15000, (int) $reservation->paid_total);
        $this->assertSame(
            $reservation->grandTotal()->subtract($this->eur(15000))->minorUnits,
            $reservation->balanceDue()->minorUnits,
        );
    }

    // ------------------------------------------------------------------

    private function book(): Reservation
    {
        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: $this->date(20),
            checkOut: $this->date(23),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Marta',
                'last_name' => 'Silva',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: $this->date(0),
        ));
    }

    private function cashBalance(): Money
    {
        return $this->poster->balanceOf($this->poster->account(Accounts::CLEARING));
    }

    private function eur(int $minorUnits): Money
    {
        return Money::of($minorUnits, 'EUR');
    }

    private function date(int $offsetDays): CarbonImmutable
    {
        return CarbonImmutable::now($this->property->timezone)->startOfDay()->addDays($offsetDays);
    }
}
