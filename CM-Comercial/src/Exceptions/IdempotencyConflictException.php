<?php
declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a concurrent request wins the persistent idempotency key race.
 *
 * The webhook transaction owner must roll back the losing transaction and treat
 * the event as already processed. This exception deliberately carries no payload
 * or credentials from the gateway.
 */
final class IdempotencyConflictException extends RuntimeException
{
    public function __construct(string $message = 'Idempotency key already processed.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
