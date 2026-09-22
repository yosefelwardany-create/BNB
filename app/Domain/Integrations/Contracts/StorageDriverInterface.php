<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

/**
 * Object storage, expressed in the terms the document module needs.
 *
 * Laravel's filesystem already abstracts the drivers; this narrower interface
 * exists so document handling can add the behaviour the product requires —
 * tenant-prefixed keys, signed temporary URLs and virus-scan hooks — without
 * every caller reaching for the raw disk.
 */
interface StorageDriverInterface
{
    /**
     * Store file contents and return the storage key.
     */
    public function put(string $organizationId, string $path, string $contents, string $mimeType): string;

    public function get(string $key): ?string;

    public function delete(string $key): bool;

    public function exists(string $key): bool;

    public function size(string $key): int;

    /**
     * A URL that grants time-limited read access without exposing the bucket.
     */
    public function temporaryUrl(string $key, int $minutes = 15): string;
}
