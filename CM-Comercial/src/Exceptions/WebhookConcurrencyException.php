<?php
declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Controlled failure for transient InnoDB concurrency conflicts. */
final class WebhookConcurrencyException extends RuntimeException
{
    public function __construct(string $message = 'Webhook concurrency conflict.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
