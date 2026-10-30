<?php

declare(strict_types=1);

namespace App\Domain\Websites\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A draft saved over one the editor never saw: someone else — or the same
 * person in another tab — saved first. Refused, so nobody's work is lost.
 */
final class WebsiteDraftConflict extends HttpException
{
    public function __construct()
    {
        parent::__construct(409, 'Someone else changed this site since you opened it. Reload to see their changes.');
    }
}
