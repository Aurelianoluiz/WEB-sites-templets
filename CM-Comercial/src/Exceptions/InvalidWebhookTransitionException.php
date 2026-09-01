<?php
declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Raised when a webhook requests a transition forbidden by the payment state machine. */
final class InvalidWebhookTransitionException extends RuntimeException
{
    public function __construct(string $message = 'Invalid webhook state transition.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
