<?php

namespace App\Mcp;

use RuntimeException;
use Throwable;

class ToolException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 500,
        public readonly mixed $data = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
