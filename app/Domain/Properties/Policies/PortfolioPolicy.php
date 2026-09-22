<?php

declare(strict_types=1);

namespace App\Domain\Properties\Policies;

use App\Domain\Properties\Models\Portfolio;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

class PortfolioPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, 'portfolios.view');
    }

    public function view(User $user, Portfolio $portfolio): bool
    {
        return $this->access->allows($user, 'portfolios.view');
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'portfolios.manage');
    }

    public function update(User $user, Portfolio $portfolio): bool
    {
        return $this->access->allows($user, 'portfolios.manage');
    }

    public function delete(User $user, Portfolio $portfolio): bool
    {
        return $this->access->allows($user, 'portfolios.manage');
    }
}
