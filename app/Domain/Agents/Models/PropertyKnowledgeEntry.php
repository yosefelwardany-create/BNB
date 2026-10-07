<?php

declare(strict_types=1);

namespace App\Domain\Agents\Models;

use App\Domain\Agents\Services\TeamKnowledge;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fact in a property's own knowledge base, under its topic.
 *
 * See {@see TeamKnowledge}.
 */
class PropertyKnowledgeEntry extends BaseModel
{
    use BelongsToOrganization;

    public const SOURCE_AGENT = 'agent';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'organization_id', 'property_id', 'topic', 'topic_key', 'content',
        'source', 'created_by_id', 'updated_by_id',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
