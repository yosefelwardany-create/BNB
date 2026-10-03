<?php

declare(strict_types=1);

namespace App\Domain\Agents\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentMemory extends BaseModel
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = ['organization_id', 'property_id', 'user_id', 'content'];
}
