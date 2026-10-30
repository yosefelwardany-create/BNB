<?php

declare(strict_types=1);

namespace App\Domain\Websites\Models;

use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo a site has of its own — uploaded in the editor for a block —
 * beside the photos of its homes. Kept on the `media` disk.
 *
 * Not tenant-scoped, as its site is not.
 *
 * @property string $id
 * @property string $website_id
 * @property string $path
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $alt
 */
class WebsiteImage extends BaseModel
{
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * How a block refers to it.
     */
    public function ref(): string
    {
        return 'img:'.$this->getKey();
    }
}
