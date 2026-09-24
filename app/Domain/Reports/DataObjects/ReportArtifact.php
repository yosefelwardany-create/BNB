<?php

declare(strict_types=1);

namespace App\Domain\Reports\DataObjects;

/**
 * A rendered report, ready to be handed to a destination.
 *
 * Built once per run and shared by every destination, so a report emailed and
 * posted to a webhook in the same run is provably the same bytes. Rendering it
 * per destination would let two recipients act on figures that differ because
 * a booking landed between them.
 */
final class ReportArtifact
{
    /**
     * @param  list<string>  $notes  The report's own caveats. They travel with
     *                               the figures to every destination, because a
     *                               reader who acts on a number without knowing
     *                               what it excludes is the failure this is
     *                               preventing.
     */
    public function __construct(
        public readonly string $filename,
        public readonly string $contents,
        public readonly string $mimeType,
        /** csv or json. */
        public readonly string $format,
        public readonly int $rowCount,
        public readonly array $notes = [],
    ) {}

    public function checksum(): string
    {
        return hash('sha256', $this->contents);
    }

    public function sizeBytes(): int
    {
        return strlen($this->contents);
    }
}
