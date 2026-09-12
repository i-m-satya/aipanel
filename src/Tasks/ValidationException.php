<?php

declare(strict_types=1);

namespace AIPanel\Tasks;

final class ValidationException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Task validation failed: ' . implode('; ', $errors));
    }
}
