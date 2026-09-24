<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Documents\Models\Document;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\OwnerAccounting\Models\OwnerStatementLine;
use App\Domain\Payments\Models\Payment;
use App\Domain\Platform\Services\SequenceGenerator;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Models\ReservationCharge;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The documents this platform hands to people.
 *
 * Owners want a document, not a screen: a statement they can file, forward to an
 * accountant and read on paper. Guests want an invoice and a receipt for the same
 * reasons. Until now the data existed and nothing rendered it.
 *
 * Two rules hold throughout:
 *
 *  - **A document is a snapshot, not a view.** It is rendered from the record as
 *    it stands and stored as a file. Re-rendering later would show the record as
 *    it is now, which is not what the owner received and settles no dispute.
 *  - **A document may not claim more than the record does.** A receipt for a
 *    simulated payment says so on its face; an invoice for a channel-collected
 *    booking says who actually took the money. These are the artefacts people
 *    forward to accountants, which is exactly why they must not overstate.
 */
class DocumentBuilder
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly TenantContext $tenancy,
        private readonly SequenceGenerator $sequences,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * An owner statement as a PDF.
     *
     * Regenerating replaces the file for a draft — a draft is a working document
     * and the previous render is of no interest. Once approved, the document is
     * kept: that is the copy the owner was sent.
     */
    public function ownerStatement(OwnerStatement $statement): Document
    {
        $statement->loadMissing(['owner', 'lines.property', 'lines.reservation']);

        $organization = $this->tenancy->organizationOrFail();
        $currency = $statement->currency;

        $money = fn (int|string|null $minor): string => Money::of((int) $minor, $currency)->toDecimal()
            .' '.$currency;

        $summary = array_values(array_filter([
            ['label' => 'Opening balance', 'amount' => $money($statement->opening_balance), 'keep' => (int) $statement->opening_balance !== 0],
            ['label' => 'Accommodation', 'amount' => $money($statement->accommodation_revenue), 'keep' => true],
            ['label' => 'Fees', 'amount' => $money($statement->fee_revenue), 'keep' => (int) $statement->fee_revenue !== 0],
            ['label' => 'Taxes collected', 'amount' => $money($statement->taxes_collected), 'keep' => (int) $statement->taxes_collected !== 0],
            ['label' => 'Channel commission', 'amount' => $money(-abs((int) $statement->channel_commission)), 'keep' => (int) $statement->channel_commission !== 0],
            ['label' => 'Payment fees', 'amount' => $money(-abs((int) $statement->payment_fees)), 'keep' => (int) $statement->payment_fees !== 0],
            ['label' => 'Management fee', 'amount' => $money(-abs((int) $statement->management_fee)), 'keep' => true],
            ['label' => 'Expenses', 'amount' => $money(-abs((int) $statement->expenses_total)), 'keep' => (int) $statement->expenses_total !== 0],
            ['label' => 'Adjustments', 'amount' => $money($statement->adjustments_total), 'keep' => (int) $statement->adjustments_total !== 0],
            ['label' => 'Reserve withheld', 'amount' => $money(-abs((int) $statement->reserve_withheld)), 'keep' => (int) $statement->reserve_withheld !== 0],
        ], static fn (array $row): bool => $row['keep']));

        $summary = array_map(
            static fn (array $row): array => ['label' => $row['label'], 'amount' => $row['amount']],
            $summary,
        );

        $html = $this->pdf->render('documents.owner-statement', [
            'title' => sprintf('Statement %s', $statement->reference),
            'heading' => 'Owner statement',
            'subheading' => $statement->owner?->display_name,
            'reference' => $statement->reference,
            'organization' => $organization,
            'producedAt' => now($organization->timezone)->toDayDateTimeString(),
            'statement' => $statement,
            'owner' => $statement->owner,
            'period' => sprintf(
                '%s to %s',
                $statement->period_start?->toFormattedDateString(),
                $statement->period_end?->toFormattedDateString(),
            ),
            'summary' => $summary,
            'money' => $money,
            'lines' => $statement->lines
                ->sortBy(['sort_order', 'line_date'])
                ->map(fn (OwnerStatementLine $line): array => [
                    'date' => $line->line_date?->toFormattedDateString() ?? '',
                    'description' => $line->description,
                    'explanation' => $line->explanation,
                    'property' => $line->property?->name ?? '',
                    // The share is printed per line, because a joint owner
                    // checking their half against the booking total needs to see
                    // why the two differ.
                    'share' => $line->ownership_percentage === null
                        ? ''
                        : rtrim(rtrim(number_format((float) $line->ownership_percentage, 2), '0'), '.').'%',
                    'amount' => $money($line->amount),
                ])
                ->values()
                ->all(),
            'terms' => $this->describeTerms($statement),
        ]);

        return $this->store(
            $html,
            $statement,
            kind: Document::STATEMENT,
            name: sprintf('Owner statement %s.pdf', $statement->reference),
            ownerVisible: true,
            guestVisible: false,
            // The file is replaced while a statement is still a draft; an approved
            // statement's document is the copy the owner was sent and is kept.
            replaceExisting: $statement->status === OwnerStatement::STATUS_DRAFT,
        );
    }

    /**
     * An invoice for a booking.
     */
    public function invoice(Reservation $reservation): Document
    {
        $reservation->loadMissing(['guest', 'property', 'charges', 'channelAccount']);

        $organization = $this->tenancy->organizationOrFail();
        $currency = $reservation->currency;

        $money = fn (int|string|null $minor): string => Money::of((int) $minor, $currency)->toDecimal()
            .' '.$currency;

        $reference = $this->sequences->next(
            $organization->getKey(),
            SequenceGenerator::INVOICE,
            'INV',
            6,
        );

        $balance = (int) $reservation->balance_due;

        $html = $this->pdf->render('documents.invoice', [
            'title' => sprintf('Invoice %s', $reference),
            'heading' => 'Invoice',
            'subheading' => sprintf('Booking %s', $reservation->confirmation_code),
            'reference' => $reference,
            'organization' => $organization,
            'producedAt' => now($organization->timezone)->toDayDateTimeString(),
            'guestName' => $reservation->guest?->display_name ?? 'Guest',
            'guestEmail' => $reservation->guest?->email,
            'property' => $reservation->property?->name ?? '',
            'stay' => sprintf(
                '%s to %s (%d night%s)',
                $reservation->check_in_date?->toFormattedDateString(),
                $reservation->check_out_date?->toFormattedDateString(),
                (int) $reservation->nights,
                (int) $reservation->nights === 1 ? '' : 's',
            ),
            'charges' => $reservation->charges
                ->map(fn (ReservationCharge $charge): array => [
                    'label' => $charge->label,
                    'description' => $charge->description,
                    'quantity' => (float) $charge->quantity == 1.0
                        ? ''
                        : rtrim(rtrim(number_format((float) $charge->quantity, 2), '0'), '.'),
                    'amount' => $money($charge->amount),
                ])
                ->values()
                ->all(),
            'total' => $money($reservation->grand_total),
            'paid' => $money($reservation->paid_total),
            'refunded' => (int) $reservation->refunded_total === 0
                ? null
                : $money($reservation->refunded_total),
            // A negative balance is the guest in credit, which is a different
            // sentence from "outstanding" and is labelled as one.
            'outstanding' => $money(abs($balance)),
            'outstandingIsCredit' => $balance < 0,
            'collectedByChannel' => $reservation->channelAccount?->collects_payment === true,
            'channelName' => $reservation->channelAccount?->name
                ?? ChannelAccount::query()->find($reservation->channel_account_id)?->name
                ?? 'the booking channel',
            'currency' => $currency,
        ]);

        return $this->store(
            $html,
            $reservation,
            kind: Document::INVOICE,
            name: sprintf('Invoice %s.pdf', $reference),
            ownerVisible: false,
            guestVisible: true,
            replaceExisting: false,
            metadata: ['reference' => $reference],
        );
    }

    /**
     * A receipt for one captured payment.
     */
    public function receipt(Payment $payment): Document
    {
        $payment->loadMissing(['reservation.guest', 'guest']);

        $organization = $this->tenancy->organizationOrFail();

        $money = fn (int|string|null $minor): string => Money::of(
            (int) $minor,
            $payment->currency,
        )->toDecimal().' '.$payment->currency;

        $collectedBy = $payment->reservation?->source === null
            ? 'the booking channel'
            : Str::headline((string) $payment->reservation->source);

        $details = array_filter([
            'Reference' => $payment->reference,
            'Method' => $payment->method === null ? null : Str::headline($payment->method),
            'Card' => $payment->instrument_last4 === null
                ? null
                : trim(sprintf('%s ending %s', (string) $payment->instrument_brand, $payment->instrument_last4)),
            'Captured' => $payment->captured_at?->setTimezone($organization->timezone)->toDayDateTimeString(),
            'Amount captured' => $money($payment->captured_amount),
            'Processing fee' => (int) $payment->fee_amount === 0 ? null : $money($payment->fee_amount),
            'Refunded' => (int) $payment->refunded_amount === 0 ? null : $money($payment->refunded_amount),
        ], static fn ($value): bool => $value !== null && $value !== '');

        $html = $this->pdf->render('documents.receipt', [
            'title' => sprintf('Receipt %s', $payment->reference),
            'heading' => $payment->is_simulated ? 'Simulated payment record' : 'Receipt',
            'subheading' => null,
            'reference' => $payment->reference,
            'organization' => $organization,
            'producedAt' => now($organization->timezone)->toDayDateTimeString(),
            'payment' => $payment,
            'payerName' => $payment->reservation?->guest?->display_name
                ?? $payment->guest?->display_name
                ?? 'Guest',
            'reservationCode' => $payment->reservation?->confirmation_code,
            'amount' => $money($payment->captured_amount ?: $payment->amount),
            'capturedAt' => $payment->captured_at
                ?->setTimezone($organization->timezone)
                ->toDayDateTimeString() ?? '',
            'collectedBy' => $collectedBy,
            'details' => $details,
        ]);

        return $this->store(
            $html,
            $payment,
            kind: Document::RECEIPT,
            name: sprintf('Receipt %s.pdf', $payment->reference),
            ownerVisible: false,
            guestVisible: true,
            replaceExisting: false,
        );
    }

    /**
     * Write the rendered bytes and record the document.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function store(
        string $contents,
        object $subject,
        string $kind,
        string $name,
        bool $ownerVisible,
        bool $guestVisible,
        bool $replaceExisting,
        array $metadata = [],
    ): Document {
        $organization = $this->tenancy->organizationOrFail();
        $disk = (string) config('filesystems.default');

        $path = sprintf(
            'organizations/%s/documents/%s/%s.pdf',
            $organization->getKey(),
            $kind,
            Str::ulid()->toBase32(),
        );

        return DB::transaction(function () use (
            $contents, $subject, $kind, $name, $ownerVisible,
            $guestVisible, $replaceExisting, $metadata, $organization, $disk, $path
        ): Document {
            if ($replaceExisting) {
                $this->removeExisting($subject, $kind, $disk);
            }

            Storage::disk($disk)->put($path, $contents);

            $document = Document::query()->create([
                'organization_id' => $organization->getKey(),
                'documentable_type' => $subject->getMorphClass(),
                'documentable_id' => $subject->getKey(),
                'name' => $name,
                'kind' => $kind,
                'disk' => $disk,
                'path' => $path,
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($contents),
                // So a dispute about whether a stored document is the one that
                // was sent can be settled by comparing hashes.
                'checksum' => hash('sha256', $contents),
                'is_owner_visible' => $ownerVisible,
                'is_guest_visible' => $guestVisible,
                // Every one of these names a person and what they paid.
                'contains_personal_data' => true,
                'uploaded_by_id' => auth()->id(),
                'metadata' => $metadata === [] ? null : $metadata,
            ]);

            $this->audit->created($document, sprintf('Generated %s: %s.', $kind, $name));

            return $document;
        });
    }

    /**
     * Drop a previous render of the same kind for the same subject.
     *
     * Only reached for drafts. The file is deleted after the row, and a failure
     * to delete is tolerated: an orphaned file costs storage, whereas a row
     * pointing at a file that is gone is a download that 404s for an owner.
     */
    private function removeExisting(object $subject, string $kind, string $disk): void
    {
        $existing = Document::query()
            ->where('documentable_type', $subject->getMorphClass())
            ->where('documentable_id', $subject->getKey())
            ->where('kind', $kind)
            ->get();

        foreach ($existing as $document) {
            $path = $document->path;

            $document->delete();

            try {
                Storage::disk($document->disk ?? $disk)->delete($path);
            } catch (\Throwable) {
                // Left behind rather than failing the regeneration.
            }
        }
    }

    /**
     * The commission terms as recorded on the statement.
     *
     * Read from the statement's own snapshot, never from the owner's current
     * agreement: a statement has to keep saying what it was calculated under
     * after the terms change.
     *
     * @return array<string, string>
     */
    private function describeTerms(OwnerStatement $statement): array
    {
        $snapshot = $statement->agreement_snapshot;

        if (! is_array($snapshot) || $snapshot === []) {
            return [];
        }

        $terms = [];

        if (isset($snapshot['name'])) {
            $terms['Agreement'] = (string) $snapshot['name'];
        }

        if (isset($snapshot['commission_model'])) {
            $terms['Commission basis'] = Str::headline((string) $snapshot['commission_model']);
        }

        if (isset($snapshot['commission_rate']) && $snapshot['commission_rate'] !== null) {
            $terms['Commission rate'] = rtrim(rtrim(
                number_format((float) $snapshot['commission_rate'], 2),
                '0',
            ), '.').'%';
        }

        // The bases, because "twenty per cent of what, exactly" is the question
        // every statement dispute turns on.
        $charged = array_keys(array_filter([
            'accommodation' => $snapshot['commission_on_accommodation'] ?? false,
            'fees' => $snapshot['commission_on_fees'] ?? false,
            'taxes' => $snapshot['commission_on_taxes'] ?? false,
        ]));

        if ($charged !== []) {
            $terms['Charged on'] = implode(', ', $charged);
        }

        return $terms;
    }
}
