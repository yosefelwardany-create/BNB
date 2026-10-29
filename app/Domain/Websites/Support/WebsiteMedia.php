<?php

declare(strict_types=1);

namespace App\Domain\Websites\Support;

use App\Domain\Websites\Models\Website;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A site's own images — its logo and hero photo — and where a browser loads
 * them from.
 *
 * Stored on the `media` disk. A disk with its own public address (Cloudflare
 * R2, once configured) is linked directly; otherwise the site's media route
 * streams the file, because the local disk has no address of its own.
 */
final class WebsiteMedia
{
    /** @var list<string> */
    public const KINDS = ['logo', 'hero'];

    public const DISK = 'media';

    /**
     * Store an image as the site's logo or hero, replacing the previous one.
     */
    public static function store(Website $site, UploadedFile $file, string $kind): string
    {
        $disk = Storage::disk(self::DISK);
        $previous = $site->setting($kind.'_path');

        $path = $file->store('websites/'.$site->getKey(), self::DISK);

        $site->forceFill(['settings' => array_replace($site->settings ?? [], [$kind.'_path' => $path])])->save();

        if (is_string($previous) && $previous !== $path) {
            $disk->delete($previous);
        }

        return $path;
    }

    public static function url(Website $site, string $kind): ?string
    {
        $path = $site->setting($kind.'_path');

        if (! is_string($path) || $path === '') {
            return null;
        }

        if (config('filesystems.disks.'.self::DISK.'.url') !== null) {
            return Storage::disk(self::DISK)->url($path);
        }

        // The path in the query string, so a replaced logo is a new address
        // and no browser keeps showing the old one from its cache.
        return $site->url('media/'.$kind).'?v='.substr(sha1($path), 0, 10);
    }
}
