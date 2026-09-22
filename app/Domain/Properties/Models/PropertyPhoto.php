<?php

declare(strict_types=1);

namespace App\Domain\Properties\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An image of a property or of one of its units.
 *
 * Files live on the configured disk; only the key is stored here. Listings
 * reference these rows rather than holding their own copies, so replacing a
 * photo updates every listing that shows it.
 */
class PropertyPhoto extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'property_id',
        'unit_id',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'caption',
        'room_type',
        'position',
        'is_cover',
    ];

    protected function casts(): array
    {
        return ['is_cover' => 'boolean'];
    }

    protected $attributes = [
        'disk' => 'local',
        'position' => 0,
        'is_cover' => false,
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * A URL the browser can load. Private disks produce a time-limited signed
     * URL rather than a permanent public one.
     */
    public function url(int $minutes = 60): ?string
    {
        $disk = Storage::disk($this->disk);

        if (in_array($this->disk, ['public', 's3-public'], true)) {
            return $disk->url($this->path);
        }

        try {
            return $disk->temporaryUrl($this->path, now()->addMinutes($minutes));
        } catch (\Throwable) {
            // Local development disks cannot sign URLs; fall back to the
            // application route that streams the file.
            return route('api.v1.properties.photos.show', ['photo' => $this->getKey()]);
        }
    }
}
