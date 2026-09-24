<?php

declare(strict_types=1);

namespace Tests\Feature\Experience;

use App\Domain\Documents\Models\Document;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\OwnerAccounting\Services\OwnerStatementBuilder;
use App\Domain\Owners\Models\ManagementAgreement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Services\OwnerDirectory;
use App\Domain\Owners\Services\OwnershipLedger;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The documents people file.
 *
 * Owners want a document, not a screen, and the ones this produces are the
 * artefacts somebody forwards to an accountant. So these check two things: that
 * a real PDF comes out, and that it does not claim more than the record does.
 *
 * The second is the one that matters. A receipt stating money was received when
 * no processor handled it is the most damaging thing this product could print.
 */
class GeneratedDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        ['organization' => $this->organization, 'user' => $this->user] = $this->createTenantWithAdmin(
            ['base_currency' => 'EUR'],
        );

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 12000,
            'cleaning_fee' => 5000,
            'max_occupancy' => 4,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        $this->actingAsUser($this->user, $this->organization);
    }

    /**
     * A PDF is a PDF: it begins with the magic bytes and carries a trailer.
     * Checking the extension would pass for an HTML file named .pdf.
     */
    private function assertIsPdf(Document $document): void
    {
        $contents = Storage::disk($document->disk)->get($document->path);

        $this->assertIsString($contents);
        $this->assertStringStartsWith('%PDF-', $contents);
        $this->assertStringContainsString('%%EOF', $contents);
        $this->assertGreaterThan(800, strlen($contents), 'The PDF is implausibly small.');
        $this->assertSame(hash('sha256', $contents), $document->checksum);
        $this->assertSame(strlen($contents), (int) $document->size_bytes);
    }

    private function book(int $offset = 3, int $nights = 3): Reservation
    {
        return app(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: CarbonImmutable::today($this->property->timezone)->addDays($offset),
            checkOut: CarbonImmutable::today($this->property->timezone)->addDays($offset + $nights),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Marta',
                'last_name' => 'Silva',
                'email' => 'marta-'.uniqid().'@guests.test',
            ],
        ));
    }

    // ---------------------------------------------------------------------
    // Invoices
    // ---------------------------------------------------------------------

    public function test_it_produces_an_invoice_for_a_booking(): void
    {
        $reservation = $this->book();

        $response = $this->postJson("/api/v1/reservations/{$reservation->getKey()}/invoice")
            ->assertCreated()
            ->assertJsonPath('data.kind', 'invoice')
            ->assertJsonPath('data.mime_type', 'application/pdf');

        $document = Document::query()->findOrFail($response->json('data.id'));

        $this->assertIsPdf($document);

        // A guest may have their own invoice; an owner has no business with it.
        $this->assertTrue((bool) $document->is_guest_visible);
        $this->assertFalse((bool) $document->is_owner_visible);
        $this->assertTrue((bool) $document->contains_personal_data);
    }

    public function test_each_invoice_gets_its_own_number(): void
    {
        $first = $this->book(3);
        $second = $this->book(20);

        $a = $this->postJson("/api/v1/reservations/{$first->getKey()}/invoice")->json('data.id');
        $b = $this->postJson("/api/v1/reservations/{$second->getKey()}/invoice")->json('data.id');

        $numbers = Document::query()
            ->whereIn('id', [$a, $b])
            ->get()
            ->map(fn (Document $d): ?string => $d->metadata['reference'] ?? null);

        $this->assertCount(2, array_unique($numbers->all()));

        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^INV-\d{6}$/', (string) $number);
        }
    }

    // ---------------------------------------------------------------------
    // Receipts, and the honesty that matters most
    // ---------------------------------------------------------------------

    public function test_a_receipt_for_a_simulated_payment_says_so_on_its_face(): void
    {
        $reservation = $this->book();

        $payment = app(PaymentService::class)->charge(
            Money::of(10000, 'EUR'),
            $reservation,
            ['description' => 'Deposit'],
        );

        // The bundled provider is a simulation, so this must be flagged.
        $this->assertTrue((bool) $payment->is_simulated);

        $response = $this->postJson("/api/v1/payments/{$payment->getKey()}/receipt")
            ->assertCreated()
            ->assertJsonPath('meta.is_simulated', true);

        $document = Document::query()->findOrFail($response->json('data.id'));

        $this->assertIsPdf($document);

        // The warning must be in the file, not only in the JSON. A receipt is
        // what somebody forwards to an accountant, and the accountant never
        // sees the API response.
        $contents = Storage::disk($document->disk)->get($document->path);

        $this->assertStringContainsString('No money moved', $this->textOf($contents));
    }

    public function test_a_receipt_for_a_channel_collected_payment_names_who_took_the_money(): void
    {
        $reservation = $this->book();

        $payment = app(PaymentService::class)->recordExternalPayment(
            Money::of(20000, 'EUR'),
            $reservation,
            [
                'method' => 'channel',
                'description' => 'Collected by the channel',
                'is_collected_by_us' => false,
            ],
        );

        // Not simulated: the money really moved. It just moved somewhere else.
        $this->assertFalse((bool) $payment->is_simulated);
        $this->assertFalse((bool) $payment->is_collected_by_us);

        $response = $this->postJson("/api/v1/payments/{$payment->getKey()}/receipt")
            ->assertCreated()
            ->assertJsonPath('meta.is_simulated', false)
            ->assertJsonPath('meta.is_collected_by_us', false);

        $document = Document::query()->findOrFail($response->json('data.id'));
        $text = $this->textOf(Storage::disk($document->disk)->get($document->path));

        $this->assertStringContainsString('has not reached our account', $text);
    }

    public function test_an_uncaptured_payment_cannot_be_receipted(): void
    {
        $reservation = $this->book();

        $payment = app(PaymentService::class)->authorize(
            Money::of(10000, 'EUR'),
            $reservation,
        );

        // A receipt for money never taken is the one document nobody should be
        // able to produce.
        $this->postJson("/api/v1/payments/{$payment->getKey()}/receipt")
            ->assertStatus(422);

        $this->assertSame(0, Document::query()->where('kind', 'receipt')->count());
    }

    // ---------------------------------------------------------------------
    // Owner statements
    // ---------------------------------------------------------------------

    public function test_it_produces_an_owner_statement_that_an_owner_may_read(): void
    {
        $statement = $this->buildStatement();

        $response = $this->postJson("/api/v1/owner-statements/{$statement->getKey()}/document")
            ->assertCreated()
            ->assertJsonPath('data.kind', 'statement');

        $document = Document::query()->findOrFail($response->json('data.id'));

        $this->assertIsPdf($document);

        // The whole point: the owner can have it.
        $this->assertTrue((bool) $document->is_owner_visible);
        $this->assertFalse((bool) $document->is_guest_visible);
    }

    public function test_a_draft_statement_is_marked_as_a_draft_in_the_document(): void
    {
        $statement = $this->buildStatement();

        $this->assertSame(OwnerStatement::STATUS_DRAFT, $statement->status);

        $response = $this->postJson("/api/v1/owner-statements/{$statement->getKey()}/document")
            ->assertCreated();

        $document = Document::query()->findOrFail($response->json('data.id'));
        $text = $this->textOf(Storage::disk($document->disk)->get($document->path));

        // On the page, not only in the API message. A draft an owner mistakes
        // for final is a payment they expect and do not receive.
        $this->assertStringContainsString('Draft', $text);
    }

    public function test_regenerating_a_draft_replaces_the_previous_file(): void
    {
        $statement = $this->buildStatement();

        $first = $this->postJson("/api/v1/owner-statements/{$statement->getKey()}/document")
            ->json('data.id');

        $firstPath = Document::query()->findOrFail($first)->path;

        $this->postJson("/api/v1/owner-statements/{$statement->getKey()}/document")
            ->assertCreated();

        // A draft is a working document: the previous render is of no interest,
        // and keeping every one would leave an owner choosing between five.
        $this->assertSame(
            1,
            Document::query()
                ->where('documentable_id', $statement->getKey())
                ->where('kind', 'statement')
                ->count(),
        );

        Storage::disk(config('filesystems.default'))->assertMissing($firstPath);
    }

    public function test_an_approved_statements_document_is_kept(): void
    {
        $statement = $this->buildStatement();

        app(OwnerStatementBuilder::class)->approve($statement);

        $approved = $statement->fresh();

        $this->postJson("/api/v1/owner-statements/{$approved->getKey()}/document")->assertCreated();
        $this->postJson("/api/v1/owner-statements/{$approved->getKey()}/document")->assertCreated();

        // Once approved, a document is the copy the owner was sent. Replacing it
        // would destroy the only record of what they received.
        $this->assertSame(
            2,
            Document::query()
                ->where('documentable_id', $approved->getKey())
                ->where('kind', 'statement')
                ->count(),
        );
    }

    public function test_the_statement_prints_the_terms_it_was_calculated_under(): void
    {
        $statement = $this->buildStatement();

        $response = $this->postJson("/api/v1/owner-statements/{$statement->getKey()}/document")
            ->assertCreated();

        $document = Document::query()->findOrFail($response->json('data.id'));
        $text = $this->textOf(Storage::disk($document->disk)->get($document->path));

        // "Management fee 4,200" and nothing else is the statement that starts an
        // argument nobody can settle a year later.
        $this->assertStringContainsString('How this was calculated', $text);
        $this->assertStringContainsString('18', $text, 'The commission rate should appear.');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function buildStatement(): OwnerStatement
    {
        $owner = app(OwnerDirectory::class)->create([
            'first_name' => 'Helena',
            'last_name' => 'Ferreira',
            'email' => 'helena@owners.test',
            'payout_currency' => 'EUR',
        ]);

        app(OwnershipLedger::class)->assign($this->property, $owner, [
            'ownership_percentage' => 100,
            'starts_on' => CarbonImmutable::today()->subYear()->toDateString(),
        ]);

        ManagementAgreement::query()->create([
            'organization_id' => $this->organization->getKey(),
            'owner_id' => $owner->getKey(),
            'property_id' => $this->property->getKey(),
            'name' => 'Full management',
            'commission_model' => ManagementAgreement::PERCENT_OF_REVENUE,
            'commission_rate' => 18,
            'currency' => 'EUR',
            'commission_on_accommodation' => true,
            'starts_on' => CarbonImmutable::today()->subYear()->toDateString(),
            'status' => 'active',
        ]);

        $this->book(2, 4);

        return app(OwnerStatementBuilder::class)->build(
            $owner->fresh(),
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays(30),
        );
    }

    /**
     * The readable text of a PDF.
     *
     * dompdf compresses its content streams, so the words are not in the file as
     * plain bytes. Each stream is located, inflated and stripped of everything
     * but its parenthesised string literals — which is all these tests need, and
     * they only ever assert that a particular warning really is on the page.
     */
    private function textOf(string $pdf): string
    {
        $text = '';
        $offset = 0;

        while (($position = strpos($pdf, "stream\n", $offset)) !== false) {
            $from = $position + 7;
            $to = strpos($pdf, 'endstream', $from);

            if ($to === false) {
                break;
            }

            $raw = rtrim(substr($pdf, $from, $to - $from), "\r\n");
            $offset = $to + 9;

            $inflated = @gzuncompress($raw);

            if ($inflated === false) {
                $inflated = @gzinflate($raw);
            }

            if (! is_string($inflated)) {
                continue;
            }

            // Text in a content stream is a parenthesised literal. dompdf emits
            // them inside an array — `[(some words)] TJ` — so the operator is not
            // adjacent to the closing bracket and must not be matched against.
            if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/', $inflated, $matches) > 0) {
                foreach ($matches[1] as $fragment) {
                    $text .= stripcslashes($fragment).' ';
                }
            }
        }

        return $text;
    }
}
