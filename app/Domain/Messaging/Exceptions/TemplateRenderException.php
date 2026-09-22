<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Raised when a template uses placeholders the renderer does not recognise.
 *
 * Only thrown in strict mode — when saving a template, where the author can
 * fix it. At send time an unknown placeholder is left visible in the output
 * instead, because a broken variable is not a reason to withhold a guest's
 * arrival instructions.
 */
class TemplateRenderException extends HttpException
{
    /**
     * @param  list<string>  $unknownPlaceholders
     */
    public function __construct(public readonly array $unknownPlaceholders)
    {
        parent::__construct(422, sprintf(
            'This template uses %s that %s not exist: %s.',
            count($unknownPlaceholders) === 1 ? 'a placeholder' : 'placeholders',
            count($unknownPlaceholders) === 1 ? 'does' : 'do',
            implode(', ', array_map(fn (string $p): string => '{{ '.$p.' }}', $unknownPlaceholders)),
        ));
    }
}
