<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * The organization bound to the current request.
     */
    protected function organization(): Organization
    {
        return app(TenantContext::class)->organizationOrFail();
    }

    /**
     * The organization bound to the current request, if there is one.
     *
     * For the handful of endpoints that describe the session rather than a
     * tenant's data. A platform administrator holds no membership anywhere, so
     * for them there is legitimately no organization and refusing would make
     * the platform console unreachable.
     */
    protected function organizationOrNull(): ?Organization
    {
        return app(TenantContext::class)->organization();
    }

    protected function currentUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * Number of records per page, capped so a client cannot ask for the whole
     * table.
     */
    protected function perPage(int $default = 25, int $max = 200): int
    {
        $requested = (int) request()->integer('per_page', $default);

        return max(1, min($requested, $max));
    }
}
