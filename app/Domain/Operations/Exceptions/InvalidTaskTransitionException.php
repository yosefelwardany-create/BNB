<?php

declare(strict_types=1);

namespace App\Domain\Operations\Exceptions;

use App\Domain\Operations\Enums\TaskStatus;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Raised when a task cannot move to the requested state.
 *
 * When completion is blocked, the outstanding items are listed: a cleaner
 * being told "cannot complete" without being told what is missing has no way
 * to finish the job.
 */
class InvalidTaskTransitionException extends HttpException
{
    /**
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly TaskStatus $from,
        public readonly TaskStatus $to,
        public readonly array $blockers = [],
    ) {
        $message = $blockers !== []
            ? 'This task is not finished: '.implode(' ', $blockers)
            : sprintf(
                'A %s task cannot become %s.%s',
                $from->label(),
                $to->label(),
                $from->allowedTransitions() === []
                    ? ' It is in a final state.'
                    : ' It can become: '.implode(', ', array_map(
                        fn (TaskStatus $s): string => $s->label(),
                        $from->allowedTransitions(),
                    )).'.',
            );

        parent::__construct(422, $message);
    }
}
